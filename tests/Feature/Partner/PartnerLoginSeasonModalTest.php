<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\Auth\Login;
use App\Models\Language;
use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\Season;
use App\Services\Partner\PartnerSeasonContext;
use App\Support\PortalCsrfDiagnostics;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerLoginSeasonModalTest extends TestCase
{
    use RefreshDatabase;

    private PartnerUser $user;

    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        $this->season = Season::create(['code' => 'W27', 'name' => 'Winter', 'active' => true, 'visible_to_partners' => true]);
        Season::create(['code' => 'S27', 'name' => 'Summer', 'active' => true, 'visible_to_partners' => true]);
        $partner = Partner::create(['erp_partner_code' => 'P1', 'name' => 'Partner', 'active' => true]);
        $this->user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'User', 'email' => 'modal@example.test',
            'password' => 'password', 'role' => 'partner_admin', 'active' => true]);
        $this->user->partners()->attach($partner);
    }

    private function signIn(): void
    {
        Livewire::test(Login::class)->set('email', $this->user->email)->set('password', 'password')
            ->call('login')->assertHasNoErrors()->assertSet('password', '')->assertRedirect(route('partner.login'));
    }

    public function test_credentials_are_first_and_authentication_does_not_implicitly_select_a_season(): void
    {
        $page = Livewire::test(Login::class)->assertDontSeeHtml('data-login-season-modal')->assertDontSeeHtml('id="partner-season"');
        $page->set('email', $this->user->email)->set('password', 'wrong')->call('login')->assertHasErrors('email')->assertSet('password', '');
        $this->assertGuest('partner');
        $this->signIn();
        $this->assertAuthenticatedAs($this->user, 'partner');
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        $this->get(route('partner.orders.select'))->assertRedirect(route('partner.seasons.select'));
        $modal = Livewire::test(Login::class)->assertSet('showSeasonModal', true)->assertSet('password', '')
            ->assertSeeHtml('<dialog')->assertSeeHtml('wire:ignore.self')->assertSeeHtml('x-init="$el.showModal()"')
            ->assertSeeHtml('@cancel.prevent')->assertSeeHtml('autofocus')->assertDontSeeHtml('wire:submit="login"');
        $modal->set('seasonId', $this->season->id)->call('selectSeason')->assertRedirect(route('partner.orders.select'));
        $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
        $this->assertFalse($modal->effects['redirectUsingNavigate'] ?? false);
    }

    public function test_even_one_season_is_explicitly_confirmed_in_the_modal(): void
    {
        Season::where('id', '<>', $this->season->id)->update(['visible_to_partners' => false]);
        $this->signIn();
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        Livewire::test(Login::class)->assertSet('showSeasonModal', true)->assertSet('seasonId', $this->season->id)
            ->call('selectSeason')->assertRedirect(route('partner.orders.select'));
    }

    public function test_no_available_season_has_a_clear_message_and_a_safe_return_to_login(): void
    {
        Season::query()->update(['visible_to_partners' => false]);
        $this->signIn();
        $page = Livewire::test(Login::class)->assertSee(__('partner.no_available_seasons'))->assertSet('showSeasonModal', true)
            ->set('languageId', Language::where('code', 'EN')->value('id'))->assertSee('Choose a season to continue');
        $page->call('cancelLogin')->assertRedirect(route('partner.login'));
        $this->assertGuest('partner');
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        $this->assertSame('en', session('partner.locale'));
    }

    public function test_a_guest_cannot_invoke_modal_actions(): void
    {
        Livewire::test(Login::class)->set('seasonId', $this->season->id)->call('selectSeason')->assertForbidden();
        Livewire::test(Login::class)->call('cancelLogin')->assertForbidden();
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
    }

    public function test_the_modal_authentication_flag_cannot_be_forged(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Login::class)->set('showSeasonModal', true);
    }

    public function test_a_deactivated_user_cannot_complete_the_modal(): void
    {
        $this->signIn();
        $page = Livewire::test(Login::class)->set('seasonId', $this->season->id);
        $this->user->update(['active' => false]);
        $page->call('selectSeason')->assertForbidden();
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
    }

    public function test_a_stale_modal_cannot_cancel_another_tabs_new_login(): void
    {
        $this->signIn();
        $page = Livewire::test(Login::class);
        $other = $this->user->replicate()->fill(['email' => 'other@example.test']);
        $other->save();
        $this->actingAs($other, 'partner');
        $page->call('cancelLogin')->assertForbidden();
        $this->assertAuthenticatedAs($other, 'partner');
    }

    private function parts(TestResponse $response): array
    {
        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $snapshot));
        $this->assertSame(1, preg_match('/data-csrf="([^"]+)"/', $html, $token));

        return [html_entity_decode($snapshot[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $token[1]];
    }

    private function nextRequest(TestResponse $response): void
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->withUnencryptedCookies($cookies);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
    }

    private function update(string $snapshot, string $token, string $method, array $updates = []): TestResponse
    {
        return $this->withCredentials()->postJson('/livewire/update', ['_token' => $token, 'components' => [[
            'snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_full_cookie_and_csrf_enforced_flow_uses_a_fresh_get_before_the_modal(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $first = $this->get(route('partner.login'));
        [$snapshot, $oldToken] = $this->parts($first);
        $this->nextRequest($first);
        $login = $this->update($snapshot, $oldToken, 'login', ['email' => $this->user->email, 'password' => 'password']);
        $login->assertOk()->assertJsonPath('components.0.effects.redirect', route('partner.login'));
        $this->assertFalse($login->json('components.0.effects.redirectUsingNavigate') ?? false);
        $this->nextRequest($login);
        $modal = $this->get(route('partner.login'))->assertSeeHtml('data-login-season-modal');
        [$snapshot, $newToken] = $this->parts($modal);
        $this->assertNotSame($oldToken, $newToken);
        $this->nextRequest($modal);
        $stale = $this->update($snapshot, $oldToken, 'selectSeason', ['seasonId' => $this->season->id])->assertStatus(419);
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        $this->nextRequest($stale);
        $chosen = $this->update($snapshot, $newToken, 'selectSeason', ['seasonId' => $this->season->id]);
        $chosen->assertOk()->assertJsonPath('components.0.effects.redirect', route('partner.orders.select'));
        $this->nextRequest($chosen);
        $this->get(route('partner.orders.select'))->assertOk()->assertSee('Winter');
    }

    public function test_csrf_diagnostics_record_only_flags_not_credentials_or_cookie_values(): void
    {
        Log::spy();
        $cookie = (string) config('session.cookie');
        $request = Request::create('https://example.test/livewire/update?email=private@example.test', 'POST',
            ['email' => 'private@example.test', 'password' => 'secret-password', '_token' => 'secret-token'], [$cookie => 'secret-cookie'], [], [
                'HTTP_COOKIE' => $cookie.'=secret-cookie; '.$cookie.'=another-secret',
                'HTTP_AUTHORIZATION' => 'Basic secret-authorization', 'HTTP_USER_AGENT' => 'Android Firefox secret-device',
            ]);
        PortalCsrfDiagnostics::record($request);
        Log::shouldHaveReceived('warning')->once()->with('Portal session/CSRF request rejected (419)', \Mockery::on(function (array $context): bool {
            $this->assertSame('POST', $context['method']);
            $this->assertSame(2, $context['session_cookie_entries']);
            $this->assertTrue($context['mobile_firefox']);
            $this->assertTrue($context['csrf_body_present']);
            $this->assertStringNotContainsString('secret', json_encode($context));
            $this->assertStringNotContainsString('private@example.test', json_encode($context));

            return true;
        }));
    }
}
