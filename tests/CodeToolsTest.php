<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use RuntimeException;

class CodeToolsTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/video-demo-'.uniqid();
        File::ensureDirectoryExists("$this->root/app/Http");
        File::ensureDirectoryExists("$this->root/resources/views/jobs");
        File::ensureDirectoryExists("$this->root/config");
        File::put("$this->root/app/Http/JobController.php", "<?php\n// Posts a job\nclass JobController {}\n");
        File::put("$this->root/resources/views/jobs/create.blade.php", "<button>Post job</button>\n");
        File::put("$this->root/app/.env.backup", "SECRET=1\n");
        File::put("$this->root/.env", "APP_KEY=secret\n");
        File::put("$this->root/config/services.php", "<?php return [];\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_reads_allowed_files_with_line_numbers(): void
    {
        $tools = new CodeTools($this->root);

        $this->assertStringContainsString('2  // Posts a job', $tools->run('read_file', ['path' => 'app/Http/JobController.php', 'from_line' => null]));
        $this->assertStringContainsString('resources/views/jobs/create.blade.php', $tools->run('list_files', ['path' => 'resources/views']));
        $this->assertStringContainsString('resources/views/jobs/create.blade.php:1', $tools->run('search_code', ['pattern' => 'post job', 'path' => null]));
    }

    public function test_never_lists_or_reads_secrets(): void
    {
        $tools = new CodeTools($this->root);

        $this->assertStringNotContainsString('.env', $tools->run('list_files', ['path' => 'app']));
        $this->assertStringNotContainsString('SECRET', $tools->run('search_code', ['pattern' => 'SECRET', 'path' => null]));
        $this->assertRefused(fn () => $tools->run('read_file', ['path' => 'app/.env.backup', 'from_line' => null]), 'secrets');
    }

    public function test_refuses_paths_outside_the_allowed_folders(): void
    {
        $tools = new CodeTools($this->root);

        $this->assertRefused(fn () => $tools->run('read_file', ['path' => '.env', 'from_line' => null]), 'outside');
        $this->assertRefused(fn () => $tools->run('read_file', ['path' => 'config/services.php', 'from_line' => null]), 'outside');
        $this->assertRefused(fn () => $tools->run('read_file', ['path' => 'app/../.env', 'from_line' => null]), 'outside');
        $this->assertRefused(fn () => $tools->run('list_files', ['path' => '../']), 'outside');
    }

    protected function assertRefused(callable $call, string $reason): void
    {
        try {
            $call();
            $this->fail('Expected the tool to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }
}
