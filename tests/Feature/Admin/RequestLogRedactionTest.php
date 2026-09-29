<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Regression guard for the audit-log redaction fix
// (see XBOARD-ALIPAY-CONFIG-INCIDENT.md 真实待办 #2):
// the old collect()->except(SENSITIVE_KEYS) only did top-level EXACT-match
// removal, so a nested config.private_key from payment form saves landed in
// v2_admin_audit_log.request_data in plaintext. The fix redacts — recursively —
// any key whose lowercase name contains a SENSITIVE_KEYS substring.
class RequestLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private string $adminPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminPath = '/api/v2/' . hash('crc32b', config('app.key'));

        $admin = User::create([
            'email' => 'audit-admin@example.com',
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'is_admin' => 1,
        ]);
        $this->actingAs($admin, 'sanctum');
    }

    public function test_nested_payment_credentials_are_redacted_in_audit_log(): void
    {
        // app_url is unset in the testing env, so payment/save bails out with a
        // 400 before validation — irrelevant here: RequestLog audits the request
        // after the response regardless of status, and fail() returns a normal
        // JsonResponse (no exception, so the post-response logging still runs).
        $this->postJson($this->adminPath . '/payment/save', [
            'name' => '支付宝',
            'payment' => 'alipay_f2f',
            'config' => [
                'app_id' => '2099999999999999',
                'private_key' => 'MIIEvQTEST-PRIVATE-KEY-PLAINTEXT',
                'public_key' => 'MIIBIjTEST-PUBLIC-KEY',
            ],
            'email_password' => 'smtp-secret',
            'api_key' => 'ak-plaintext',
        ]);

        $row = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($row, 'admin POST must be audited');
        $this->assertSame('payment.save', $row->action);

        $data = json_decode($row->request_data, true);

        $this->assertSame('[REDACTED]', $data['config']['private_key']);
        $this->assertSame('[REDACTED]', $data['config']['public_key']);
        $this->assertSame('[REDACTED]', $data['email_password']);
        $this->assertSame('[REDACTED]', $data['api_key']);
        // Non-sensitive fields must survive for audit value.
        $this->assertSame('2099999999999999', $data['config']['app_id']);
        $this->assertSame('支付宝', $data['name']);
        $this->assertSame('alipay_f2f', $data['payment']);

        $this->assertStringNotContainsString('MIIEvQTEST-PRIVATE-KEY-PLAINTEXT', $row->request_data);
        $this->assertStringNotContainsString('MIIBIjTEST-PUBLIC-KEY', $row->request_data);
        $this->assertStringNotContainsString('smtp-secret', $row->request_data);
        $this->assertStringNotContainsString('ak-plaintext', $row->request_data);
    }

    public function test_numeric_key_lists_survive_redaction_as_json_arrays(): void
    {
        $this->postJson($this->adminPath . '/payment/save', [
            'name' => '支付宝',
            'payment' => 'alipay_f2f',
            'config' => ['app_id' => '2099999999999999'],
            'ids' => [3, 1, 2],
            // Benign key carrying a sensitive substring: redacted by design
            // (fail-closed beats audit fidelity for an audit log).
            'keyword' => 'search-term',
        ]);

        $row = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($row, 'admin POST must be audited');

        $data = json_decode($row->request_data, true);
        $this->assertSame([3, 1, 2], $data['ids'], 'numeric-key lists must stay a JSON array, not become an object');
        $this->assertSame('[REDACTED]', $data['keyword']);
        $this->assertSame('2099999999999999', $data['config']['app_id']);
    }

    public function test_extended_needles_pem_values_and_query_string_are_redacted(): void
    {
        $this->postJson(
            $this->adminPath . '/payment/save?token=query-secret&payment_status=3',
            [
                'name' => '支付宝',
                'payment' => 'alipay_f2f',
                'config' => ['app_id' => '2099999999999999'],
                'passwd' => 'p@ss',
                'client_credentials' => 'cred-secret',
                'license_blob' => "-----BEGIN RSA PRIVATE KEY-----\nMIIEvQTEST\n-----END RSA PRIVATE KEY-----",
            ]
        );

        $row = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($row, 'admin POST must be audited');

        $data = json_decode($row->request_data, true);
        $this->assertSame('[REDACTED]', $data['passwd']);
        $this->assertSame('[REDACTED]', $data['client_credentials']);
        // Value-level fallback: PEM material is redacted regardless of key name —
        // the incident's root shape was "credentials under a non-matching key".
        $this->assertSame('[REDACTED]', $data['license_blob']);

        // uri column: query params go through the same redaction — secrets
        // stripped (?token=… was a bypass channel), benign params preserved.
        $this->assertStringContainsString('payment_status=3', $row->uri);
        $this->assertStringContainsString('token=%5BREDACTED%5D', $row->uri);
        $this->assertStringNotContainsString('query-secret', $row->uri);
    }

    public function test_invalid_utf8_values_do_not_destroy_the_audit_row(): void
    {
        // Raw urlencoded content is NOT parsed for POST by the test client (that is
        // the SAPI's job), so deliver the invalid bytes through the parameters bag —
        // equivalent to what $_POST would carry on a real form submission.
        $this->call('POST', $this->adminPath . '/payment/save', [
            'name' => "\xB1\x31",
            'payment' => 'alipay_f2f',
        ], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $row = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($row, 'admin POST must be audited');
        $decoded = json_decode((string) $row->request_data);
        $this->assertNotNull($decoded, 'request_data must stay valid JSON despite invalid UTF-8 input');
        $this->assertTrue(is_object($decoded) && property_exists($decoded, 'payment'), 'payload fields must survive the encoding fix');
    }

    public function test_unencodable_payloads_keep_the_audit_row_with_marker(): void
    {
        // 1e999 is valid JSON that decodes to INF; json_encode(INF) returns false,
        // which previously stored an empty payload (audit-evasion via depth/INF bombs).
        $this->call('POST', $this->adminPath . '/payment/save', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"name":"QA","payment":"alipay_f2f","config":{"app_id":"2099999999999999"},"overflow":1e999}');

        $row = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($row, 'admin POST must be audited even when the payload is unencodable');
        $this->assertSame('{"_error":"payload not encodable"}', $row->request_data);
    }
}
