<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\Redactor;

class RedactorTest extends TestCase
{
    public function test_hides_known_secret_formats(): void
    {
        $redactor = new Redactor;
        $text = implode("\n", [
            "'key' => 'sk-ant-api03-".str_repeat('a1B2', 12)."',",
            '$stripe = new StripeClient("sk_live_'.str_repeat('Ab3', 9).'");',
            'webhook: whsec_'.str_repeat('Zx9', 8),
            'aws: AKIAIOSFODNN7EXAMPLE',
            'github: ghp_'.str_repeat('a', 36),
            "-----BEGIN RSA PRIVATE KEY-----\nMIIEow\nabc\n-----END RSA PRIVATE KEY-----",
            "'client_secret' => 'q8Fz2kLm9Xv4Rt7Wp',",
        ]);

        $out = $redactor->redact($text);

        foreach (['sk-ant-api03', 'sk_live_', 'whsec_', 'AKIAIOSFODNN7EXAMPLE', 'ghp_', 'MIIEow', 'q8Fz2kLm9Xv4Rt7Wp'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        $this->assertStringContainsString('[redacted: Stripe secret key]', $out);
        $this->assertStringContainsString("'client_secret' => '[redacted: secret value]',", $out);
        $this->assertSame(7, $redactor->takeCount());
        $this->assertSame(0, $redactor->takeCount());
    }

    public function test_leaves_ordinary_code_alone(): void
    {
        $code = implode("\n", [
            "'password' => 'required|string|min:8|confirmed',",
            "'token' => env('SERVICE_TOKEN'),",
            '$request->validate([\'api_key\' => \'nullable\']);',
            '<input type="password" name="password" placeholder="Your password">',
            "Route::post('/tokens', [TokenController::class, 'store']);",
        ]);

        $this->assertSame($code, (new Redactor)->redact($code));
    }

    public function test_can_be_turned_off_and_extended(): void
    {
        config(['video-demo.ai.code.redact_patterns' => ['internal id' => '/\bACME-\d{6}\b/']]);
        $this->assertSame('id: [redacted: internal id]', (new Redactor)->redact('id: ACME-123456'));

        config(['video-demo.ai.code.redact' => false]);
        $this->assertSame('sk_live_'.str_repeat('Ab3', 9), (new Redactor)->redact('sk_live_'.str_repeat('Ab3', 9)));
    }

    public function test_code_tools_hide_secrets_in_what_claude_reads(): void
    {
        $root = sys_get_temp_dir().'/video-demo-redact-'.uniqid();
        File::ensureDirectoryExists("$root/app");
        File::put("$root/app/Billing.php", "<?php\n\$client = new StripeClient('sk_live_".str_repeat('Ab3', 9)."');\n");

        $tools = new CodeTools($root);
        $read = $tools->run('read_file', ['path' => 'app/Billing.php', 'from_line' => null]);
        $found = $tools->run('search_code', ['pattern' => 'StripeClient', 'path' => null]);
        File::deleteDirectory($root);

        $this->assertStringContainsString('[redacted: Stripe secret key]', $read);
        $this->assertStringNotContainsString('sk_live_', $read.$found);
        $this->assertSame(2, $tools->hiddenSecrets());
    }
}
