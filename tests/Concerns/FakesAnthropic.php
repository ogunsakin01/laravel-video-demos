<?php

namespace Ogunsakin01\VideoDemo\Tests\Concerns;

use Anthropic\Client;
use Anthropic\RequestOptions;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\Claude;
use Symfony\Component\Process\Process;

/**
 * Points the package's Claude client at tests/fixtures/fake-anthropic.php, so
 * the real SDK sends real requests and parses real replies, at no cost.
 * respond() scripts reply N; request() reads request N as it was sent.
 */
trait FakesAnthropic
{
    protected string $dir;

    protected Process $server;

    protected function startFakeAnthropic(): void
    {
        $this->dir = sys_get_temp_dir().'/video-demo-api-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $port = random_int(20000, 40000);
        $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__.'/../fixtures/fake-anthropic.php'], null, ['FAKE_DIR' => $this->dir]);
        $this->server->start();
        // Stop the server even if a test dies with a fatal error, or the
        // orphaned server keeps the whole test run waiting
        $server = $this->server;
        register_shutdown_function(fn () => $server->isRunning() && $server->stop(0));
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        $this->app->instance(Claude::class, new Claude(new Client(
            apiKey: 'test-key',
            baseUrl: "http://127.0.0.1:$port",
            requestOptions: RequestOptions::with(maxRetries: 0),
        )));
    }

    protected function stopFakeAnthropic(): void
    {
        $this->server->stop();
        File::deleteDirectory($this->dir);
    }

    protected function respond(int $n, array $content, string $stopReason = 'tool_use', array $usage = [], string $model = 'claude-opus-5-5'): void
    {
        File::put("$this->dir/response-$n.json", json_encode([
            'id' => "msg_$n",
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 10, 'output_tokens' => 1_000,
                'cache_creation_input_tokens' => 5_000, 'cache_read_input_tokens' => 20_000, ...$usage,
            ],
        ]));
    }

    protected function request(int $n): array
    {
        return json_decode(File::get("$this->dir/request-$n.json"), true);
    }

    /** Whether the n-th request was sent at all */
    protected function sent(int $n): bool
    {
        return File::exists("$this->dir/request-$n.json");
    }
}
