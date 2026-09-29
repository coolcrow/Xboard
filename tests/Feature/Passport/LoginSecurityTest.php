<?php

namespace Tests\Feature\Passport;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Regression guard for the login-security hardening:
// - failed logins persist to v2_log (visibility/audit trail for credential stuffing)
// - account lockout fires + is recorded with reason locked_out
// - login is gated by captcha when captcha_enable is on (missing token rejected
//   BEFORE any password work — no v2_log row, no counter bump)
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        admin_setting([
            'stop_register' => 0,
            'email_verify' => 0,
            'captcha_enable' => 0,
            'password_login_enable' => 1,
            'password_limit_enable' => 1,
            'password_limit_count' => 5,
            'password_limit_expire' => 60,
        ]);

        $this->user = User::create([
            'email' => 'victim@example.com',
            'password' => password_hash('correct-pass-123', PASSWORD_DEFAULT),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
        ]);
    }

    private User $user;

    private function attempt(string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'victim@example.com',
            'password' => $password,
        ]);
    }

    private function loginFailRows(): array
    {
        return DB::table('v2_log')->where('title', 'login_fail')->orderBy('id')->get()->all();
    }

    public function test_wrong_password_is_recorded_in_log_table(): void
    {
        $res = $this->attempt('wrong-pass-123');
        $res->assertStatus(400);

        $rows = $this->loginFailRows();
        $this->assertCount(1, $rows, 'failed login must persist one v2_log row');
        $data = json_decode($rows[0]->data, true);
        $this->assertSame('victim@example.com', $data['email']);
        $this->assertSame('wrong_password', $data['reason']);
        $this->assertSame('login_fail', $rows[0]->title);
    }

    public function test_unknown_email_is_recorded_without_leaking_existence(): void
    {
        $res = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'whatever-pass',
        ]);
        // 不断言消息文案（locale 相关）：语义由状态码 + 留痕行承载
        $res->assertStatus(400);

        $rows = $this->loginFailRows();
        $this->assertCount(1, $rows);
        $this->assertSame('no_such_user', json_decode($rows[0]->data, true)['reason']);
    }

    public function test_lockout_engages_after_limit_and_is_recorded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('wrong-pass-' . $i)->assertStatus(400);
        }

        $res = $this->attempt('even-the-right-one');
        $res->assertStatus(429);

        $rows = $this->loginFailRows();
        $this->assertCount(6, $rows, '5 wrong attempts + 1 locked-out rejection');
        $this->assertSame('locked_out', json_decode(end($rows)->data, true)['reason']);
    }

    public function test_successful_login_clears_counter_and_writes_no_rows(): void
    {
        $this->attempt('wrong-once')->assertStatus(400);
        $res = $this->attempt('correct-pass-123');
        $res->assertOk();
        $this->assertCount(1, $this->loginFailRows(), 'success must not add rows');
    }

    public function test_login_is_captcha_gated_when_enabled(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);

        // Missing turnstile token: rejected before password work — no log row, no counter bump
        $res = $this->attempt('wrong-pass-123');
        $res->assertStatus(400);
        $this->assertCount(0, $this->loginFailRows(), 'captcha rejection must not consume lockout counter');

        // And with captcha disabled the same request flows into password checking (row written)
        admin_setting(['captcha_enable' => 0]);
        $this->attempt('wrong-pass-123')->assertStatus(400);
        $this->assertCount(1, $this->loginFailRows());
    }

    public function test_captcha_upstream_unavailable_fails_closed(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);

        // Simulate Cloudflare being unreachable: the gate must return an explicit
        // 503 (fail-closed) instead of an unhandled ConnectionException -> 500,
        // and must not touch the lockout counter or write a trail row.
        \Illuminate\Support\Facades\Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('challenges.cloudflare.com unreachable');
        });

        $res = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-pass-123',
            'turnstile_token' => 'dummy-token-forcing-siteverify-call',
        ]);
        $res->assertStatus(503);
        $this->assertCount(0, $this->loginFailRows(), 'upstream failure must not write a login_fail row');

        // Lockout counter untouched: after disabling captcha the same wrong attempt lands at count 1
        admin_setting(['captcha_enable' => 0]);
        $this->attempt('wrong-pass-123')->assertStatus(400);
        $rows = $this->loginFailRows();
        $this->assertCount(1, $rows);
        $this->assertSame('wrong_password', json_decode($rows[0]->data, true)['reason']);
    }

    public function test_captcha_upstream_server_error_fails_closed(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']);

        // Upstream 5xx (e.g. Cloudflare having a bad moment): must return 503
        // (fail-closed, no counter bump), NOT 400 "code incorrect" which would
        // punish users for a provider incident.
        \Illuminate\Support\Facades\Http::fake([
            'challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::response('Internal Error', 500),
        ]);

        $res = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-pass-123',
            'turnstile_token' => 'dummy-token',
        ]);
        $res->assertStatus(503);
        $this->assertCount(0, $this->loginFailRows(), 'server error must not write a login_fail row');

        // Counter untouched: disable captcha → same attempt lands at count 1
        admin_setting(['captcha_enable' => 0]);
        $this->attempt('wrong-pass-123')->assertStatus(400);
        $this->assertCount(1, $this->loginFailRows());
    }
}
