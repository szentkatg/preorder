<?php

namespace Tests\Feature\Auth;

use App\Livewire\Partner\Auth\Login as PartnerLogin;
use App\Models\Language;
use App\Models\LoginLog;
use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\Season;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Auth\Pages\Login as AdminLogin;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class LoginAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PartnerUser $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin-audit@example.test', 'password' => 'audit-password']);
        $this->admin->assignRole(Role::findOrCreate('audit-test-admin', 'web'));
        $partner = Partner::create(['name' => 'Partner', 'erp_partner_code' => 'AUDIT-PARTNER']);
        $this->partnerUser = PartnerUser::create(['name' => 'Partner User', 'email' => 'partner-audit@example.test',
            'password' => 'audit-password', 'active' => true, 'role' => 'partner_admin', 'partner_id' => $partner->id]);
        Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        SymfonyRequest::setTrustedProxies([], -1);

        Route::middleware('web')->post('/_test/login-audit', function (Request $request) {
            return response()->json(['ok' => Auth::guard($request->input('guard'))->attempt($request->only('email', 'password'))]);
        });
        Route::middleware('web')->get('/_test/login-audit-current', fn () => response()->json(['id' => Auth::guard('web')->id()]));
    }

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedProxies([], -1);
        $this->travelBack();
        parent::tearDown();
    }

    public static function guardsAndIps(): array
    {
        return [['web', '203.0.113.15'], ['partner', '2001:db8::1234']];
    }

    #[DataProvider('guardsAndIps')]
    public function test_successful_authentication_records_one_identity_snapshot_and_the_actual_request_ip(string $guard, string $ip): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:20:30', 'UTC'));
        $user = $guard === 'web' ? $this->admin : $this->partnerUser;
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders([
            'X-Forwarded-For' => '198.51.100.99', 'Cookie' => 'private-cookie', 'Authorization' => 'private-auth-header',
        ])->post('/_test/login-audit', ['guard' => $guard, 'email' => $user->email, 'password' => 'audit-password',
            'session_id' => 'private-session', 'token' => 'private-token'])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('login_logs', 1);
        $log = LoginLog::firstOrFail();
        $this->assertSame($guard, $log->guard);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertSame($user->name, $log->user_name);
        $this->assertSame($user->email, $log->user_email);
        $this->assertSame($ip, $log->ip_address);
        $this->assertSame('2026-11-20 10:20:30', $log->logged_in_at->format('Y-m-d H:i:s'));
        foreach (['audit-password', 'private-cookie', 'private-auth-header', 'private-session', 'private-token', $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $log->toJson());
        }
    }

    public function test_admin_panel_logs_success_but_not_wrong_password_or_missing_panel_access(): void
    {
        Livewire::test(AdminLogin::class)->fillForm(['email' => $this->admin->email, 'password' => 'wrong'])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $denied = User::factory()->create(['password' => 'audit-password']);
        Livewire::test(AdminLogin::class)->fillForm(['email' => $denied->email, 'password' => 'audit-password'])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertDatabaseCount('login_logs', 0);

        Livewire::test(AdminLogin::class)->fillForm(['email' => $this->admin->email, 'password' => 'audit-password'])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'web');
        $this->assertDatabaseCount('login_logs', 1);
        $this->assertSame('web', LoginLog::first()->guard);
    }

    public function test_partner_login_is_logged_before_season_selection_but_season_changes_do_not_add_entries(): void
    {
        $season = Season::create(['code' => 'S27', 'name' => 'Summer', 'visible_to_partners' => true]);
        Livewire::test(PartnerLogin::class)->set('email', $this->partnerUser->email)->set('password', 'audit-password')
            ->call('login')->assertHasNoErrors()->assertRedirect(route('partner.login'));
        $this->assertDatabaseCount('login_logs', 1);
        $this->assertSame('partner', LoginLog::first()->guard);
        Livewire::test(PartnerLogin::class)->set('seasonId', $season->id)->call('selectSeason')->assertHasNoErrors();
        Livewire::test(PartnerLogin::class)->set('seasonId', $season->id)->call('selectSeason')->assertHasNoErrors();
        $this->assertDatabaseCount('login_logs', 1);
    }

    public function test_failed_and_inactive_partner_logins_are_not_successful_login_entries(): void
    {
        Livewire::test(PartnerLogin::class)->set('email', $this->partnerUser->email)->set('password', 'wrong')
            ->call('login')->assertHasErrors(['email']);
        $this->partnerUser->update(['active' => false]);
        Livewire::test(PartnerLogin::class)->set('email', $this->partnerUser->email)->set('password', 'audit-password')
            ->call('login')->assertHasErrors(['email']);
        $this->assertGuest('partner');
        $this->assertDatabaseCount('login_logs', 0);
    }

    public function test_existing_sessions_do_not_log_every_page_request_but_remember_cookie_restoration_is_logged(): void
    {
        $this->post('/_test/login-audit', ['guard' => 'web', 'email' => $this->admin->email, 'password' => 'audit-password'])->assertJson(['ok' => true]);
        $this->get('/_test/login-audit-current')->assertJson(['id' => $this->admin->id]);
        $this->get('/_test/login-audit-current')->assertJson(['id' => $this->admin->id]);
        $this->assertDatabaseCount('login_logs', 1);

        $this->admin->forceFill(['remember_token' => str_repeat('r', 60)])->save();
        $guard = Auth::guard('web');
        $recaller = $this->admin->id.'|'.$this->admin->remember_token.'|'.$this->admin->getAuthPassword();
        session()->forget($guard->getName());
        $guard->forgetUser();
        $guard->setRequest(Request::create('/admin', 'GET', cookies: [$guard->getRecallerName() => $recaller]));
        $this->assertEquals($this->admin->id, $guard->user()->id);
        $this->assertTrue($guard->viaRemember());
        $this->assertDatabaseCount('login_logs', 2);
    }

    public function test_identity_snapshots_survive_user_changes_and_deletion_and_guard_ids_are_distinct(): void
    {
        $this->assertSame($this->admin->id, $this->partnerUser->id);
        Auth::guard('web')->login($this->admin);
        Auth::guard('partner')->login($this->partnerUser);
        $this->admin->update(['name' => 'Changed', 'email' => 'changed@example.test']);
        $this->admin->delete();
        $this->partnerUser->delete();
        $this->assertDatabaseCount('login_logs', 2);
        $this->assertDatabaseHas('login_logs', ['guard' => 'web', 'user_name' => 'Admin User', 'user_email' => 'admin-audit@example.test']);
        $this->assertDatabaseHas('login_logs', ['guard' => 'partner', 'user_name' => 'Partner User']);
    }

    public function test_unsupported_guard_or_user_type_does_not_create_an_entry(): void
    {
        Event::dispatch(new Login('api', $this->admin, false));
        Event::dispatch(new Login('partner', $this->admin, false));
        Event::dispatch(new Login('web', $this->partnerUser, false));
        $this->assertDatabaseCount('login_logs', 0);
    }

    public function test_audit_database_failure_is_reported_without_secrets_and_does_not_break_login(): void
    {
        Schema::drop('login_logs');
        Log::shouldReceive('error')->once()->with('Login audit persistence failed.', Mockery::on(fn (array $context) => array_keys($context) === ['guard', 'user_id', 'exception_type'] && $context['guard'] === 'web'
            && $context['user_id'] === $this->admin->id));
        $this->post('/_test/login-audit', ['guard' => 'web', 'email' => $this->admin->email, 'password' => 'audit-password'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertAuthenticatedAs($this->admin, 'web');
    }
}
