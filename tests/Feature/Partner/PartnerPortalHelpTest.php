<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\Auth\Login;
use App\Models\Language;
use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\Season;
use App\Services\Partner\PartnerPortalNotice;
use App\Services\Partner\PartnerSeasonContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerPortalHelpTest extends TestCase
{
    use RefreshDatabase;

    private PartnerUser $user;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        $hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $this->english = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        session(['partner.locale' => 'hu', 'partner.language_id' => $hu->id]);
        Season::create(['code' => 'S27', 'name' => 'Summer', 'visible_to_partners' => true, 'active' => true]);
        $partner = Partner::create(['erp_partner_code' => 'P1', 'name' => 'Partner', 'active' => true]);
        $this->user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'User', 'email' => 'notice@example.test',
            'password' => 'password', 'role' => 'partner_admin', 'active' => true]);
    }

    public function test_notice_appears_after_authentication_in_the_selected_language_before_season_selection(): void
    {
        $this->get(route('partner.login'))->assertOk()->assertSeeHtml('data-partner-help')->assertDontSeeHtml('data-portal-update-notice');
        $this->actingAs($this->user, 'partner');
        $page = Livewire::test(Login::class)->set('languageId', Language::where('code', 'HU')->value('id'))
            ->assertSeeHtml('data-portal-update-notice')->assertDontSeeHtml('data-login-season-modal')
            ->assertSee('Frissült a partnerportál')->assertSee('F1')->assertSee('Ne jelenjen meg újra');
        $page->set('languageId', $this->english->id)->assertSee('The partner portal has been updated')
            ->assertSee('Do not show again')->assertSeeHtml(route('partner.help', ['locale' => 'en']))
            ->assertDispatched('partner-interface-language-changed', locale: 'en');
        $page->call('acknowledgeUpdateNotice')->assertSeeHtml('data-login-season-modal')->assertDontSeeHtml('data-portal-update-notice');
        $this->assertDatabaseCount('partner_user_notice_dismissals', 0);
        Livewire::test(Login::class)->assertSet('showUpdateNotice', true);
    }

    public function test_checked_dismissal_is_stored_per_user_and_notice_version_not_in_the_browser(): void
    {
        foreach (['partner_admin', 'address_user', 'sales_rep'] as $role) {
            $this->user->update(['role' => $role]);
            $this->actingAs($this->user, 'partner');
            Livewire::test(Login::class)->set('doNotShowUpdateNoticeAgain', true)->call('acknowledgeUpdateNotice')
                ->assertSet('showUpdateNotice', false)->assertSeeHtml('data-login-season-modal');
            session()->flush();
            Livewire::test(Login::class)->assertSet('showUpdateNotice', false);
            $this->assertDatabaseHas('partner_user_notice_dismissals', ['partner_user_id' => $this->user->id, 'notice_key' => PartnerPortalNotice::KEY]);
            DB::table('partner_user_notice_dismissals')->delete();
        }
        $other = $this->user->replicate()->fill(['email' => 'other@example.test']);
        $other->save();
        app(PartnerPortalNotice::class)->dismiss($this->user);
        app(PartnerPortalNotice::class)->dismiss($this->user);
        $this->assertDatabaseCount('partner_user_notice_dismissals', 1);
        $this->actingAs($other, 'partner');
        Livewire::test(Login::class)->assertSet('showUpdateNotice', true);
        DB::table('partner_user_notice_dismissals')->where('partner_user_id', $this->user->id)->update(['notice_key' => 'older-notice']);
        $this->actingAs($this->user, 'partner');
        Livewire::test(Login::class)->assertSet('showUpdateNotice', true);
    }

    public function test_guests_inactive_users_and_stale_login_tabs_cannot_record_a_dismissal(): void
    {
        Livewire::test(Login::class)->set('doNotShowUpdateNoticeAgain', true)->call('acknowledgeUpdateNotice')->assertForbidden();
        $this->actingAs($this->user, 'partner');
        $page = Livewire::test(Login::class)->set('doNotShowUpdateNoticeAgain', true);
        $other = $this->user->replicate()->fill(['email' => 'other@example.test']);
        $other->save();
        $this->actingAs($other, 'partner');
        $page->call('acknowledgeUpdateNotice')->assertForbidden();
        $this->actingAs($this->user, 'partner');
        $page = Livewire::test(Login::class)->set('doNotShowUpdateNoticeAgain', true);
        $this->user->update(['active' => false]);
        $page->call('acknowledgeUpdateNotice')->assertForbidden();
        $this->assertDatabaseCount('partner_user_notice_dismissals', 0);
    }

    public function test_notice_identity_state_cannot_be_forged(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Login::class)->set('showUpdateNotice', true);
    }

    public function test_help_is_a_complete_localised_html_guide_with_a_separate_pdf_download(): void
    {
        foreach (['hu', 'en'] as $locale) {
            $otherLocale = $locale === 'hu' ? 'en' : 'hu';
            session(['partner.locale' => $otherLocale]);
            $html = $this->get(route('partner.help', ['locale' => $locale]))->assertOk()
                ->assertHeader('Content-Type', 'text/html; charset=utf-8')
                ->assertSeeHtml('<html lang="'.$locale.'">')->assertSeeHtml('data-partner-help-guide')
                ->assertSeeHtml(route('partner.help.pdf', ['locale' => $locale]))
                ->assertSee($locale === 'hu' ? 'PDF letöltése' : 'Download PDF')->assertSee('F1')
                ->assertSeeHtml('id="chapter-16"')->assertSeeHtml('href="#chapter-1"')
                ->assertDontSeeHtml('<iframe')->assertDontSeeHtml('wire:snapshot')->getContent();
            $this->assertSame($otherLocale, session('partner.locale'), 'Reading another guide language must not change the portal language.');
            $this->assertDoesNotMatchRegularExpression('/@endforeach|@if\s*\(|\{\{\s*/', $html);
            preg_match_all('/<img src="([^"]+)"/', $html, $images);
            $this->assertGreaterThan(10, count($images[1]));
            foreach ($images[1] as $url) {
                $path = public_path(ltrim(parse_url($url, PHP_URL_PATH), '/'));
                $this->assertFileExists($path);
                $this->assertSame(IMAGETYPE_JPEG, getimagesize($path)[2]);
            }
            $this->get(route('partner.help.pdf', ['locale' => $locale]))->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'attachment; filename=partner-guide-'.$locale.'.pdf');
            $this->assertStringStartsWith('%PDF-', file_get_contents(resource_path('help/partner-guide-'.$locale.'.pdf')));
        }
        $this->get('/partner/help/de')->assertNotFound();
        $this->get('/partner/help/.env')->assertNotFound();
        $this->get('/partner/help/en/../../.env')->assertNotFound();
        $this->get('/partner/help/de/pdf')->assertNotFound();
        $this->get('/partner/help/.env/pdf')->assertNotFound();
        $this->get(route('partner.password.request'))->assertOk()->assertSeeHtml('data-partner-help');
    }

    public function test_help_sits_beside_the_language_selector_without_a_duplicate_top_bar(): void
    {
        $response = $this->get(route('partner.login'))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'data-partner-help'));
        $this->assertMatchesRegularExpression('/data-partner-language-tools[^>]*>.*?<select.*?<\/select>.*?data-partner-help/s', $response->getContent());
        $this->actingAs($this->user, 'partner');
        $notice = Livewire::test(Login::class)->assertSeeHtml('data-portal-update-notice');
        $this->assertSame(1, substr_count($notice->html(), 'data-partner-help'));
        $notice->call('acknowledgeUpdateNotice')->assertSeeHtml('data-login-season-modal');
        $this->assertSame(1, substr_count($notice->html(), 'data-partner-help'));
        session([PartnerSeasonContext::SESSION_KEY => Season::first()->id]);
        $orders = $this->get(route('partner.orders.select'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($orders, 'data-partner-help'));
        $this->assertMatchesRegularExpression('/data-partner-language-tools[^>]*>.*?<select.*?<\/select>.*?data-partner-help/s', $orders);
    }

    public function test_both_guides_describe_current_tabs_bulk_email_choice_and_sheet_header_reopening(): void
    {
        foreach (['hu', 'en'] as $locale) {
            $chapters = json_decode(file_get_contents(resource_path('help/content/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(16, $chapters);
            $this->assertSame($locale === 'hu' ? 'Rendelés generálása/megnyitása' : 'Generate/open order', $chapters[2]['title']);
            $this->assertStringContainsString($locale === 'hu' ? 'Rendelések listája' : 'Order list', $chapters[2]['intro']);
            $this->assertStringContainsString($locale === 'hu' ? 'megerősítés' : 'confirmation', implode(' ', $chapters[3]['paragraphs']));
            $this->assertStringContainsString($locale === 'hu' ? 'e-mail' : 'email', implode(' ', $chapters[3]['paragraphs']));
            $this->assertStringContainsString($locale === 'hu' ? 'nyelvválasztó' : 'language selector', $chapters[13]['steps'][2]);
            $html = $this->get(route('partner.help', ['locale' => $locale]))->assertOk()
                ->assertSee($locale === 'hu' ? '2026. november 27.' : '27 November 2026')
                ->assertSeeHtml('17-tomeges-bekuldes.jpg')->assertSeeHtml('16-visszanyitas.jpg')->getContent();
            $this->assertStringNotContainsString($locale === 'hu' ? 'A rendelésválasztó alatt' : 'Below the selection panel', $html);
        }
    }
}
