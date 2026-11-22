<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\Auth\Login;
use App\Models\Language;
use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\Season;
use App\Services\Partner\PartnerPortalNotice;
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

    public function test_both_public_manuals_are_real_inline_pdfs_and_no_other_file_is_served(): void
    {
        foreach (['hu', 'en'] as $locale) {
            $this->get(route('partner.help', ['locale' => $locale]))->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'inline; filename="partner-guide-'.$locale.'.pdf"');
            $this->assertStringStartsWith('%PDF-', file_get_contents(resource_path('help/partner-guide-'.$locale.'.pdf')));
        }
        $this->get('/partner/help/de')->assertNotFound();
        $this->get('/partner/help/.env')->assertNotFound();
        $this->get('/partner/help/en/../../.env')->assertNotFound();
        $this->get(route('partner.password.request'))->assertOk()->assertSeeHtml('data-partner-help');
    }
}
