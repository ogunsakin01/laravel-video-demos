<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\PestTest;

/**
 * A video's plan as a Pest browser test: the flow the video shows, checked as a test.
 */
class PestTestTest extends TestCase
{
    protected function video(array $overrides = []): Video
    {
        $t = fn (array $fields) => [...['role' => null, 'name' => null, 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null], ...$fields];
        $s = fn (string $action, array $fields = []) => [...['action' => $action, 'why' => '', 'url' => null, 'target' => null, 'value' => null, 'title' => null, 'text' => null, 'ms' => 0, 'chapter' => null, 'say' => null, 'only_on' => []], ...$fields];

        return Video::create([
            'title' => "Post a recruiter's job", 'prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'version' => 3, 'sample_data' => true,
            'plan' => ['title' => 'Post a job', 'summary' => '', 'steps' => [
                $s('card', ['title' => 'Post a job']),
                $s('goto', ['url' => '/jobs']),
                $s('click', ['target' => $t(['role' => 'link', 'name' => 'Post a job'])]),
                $s('caption', ['title' => 'The form', 'text' => 'Two fields.']),
                $s('type', ['target' => $t(['role' => 'textbox', 'label' => 'Job title']), 'value' => "Head of Support's team"]),
                $s('type', ['target' => $t(['placeholder' => 'City']), 'value' => 'Accra']),
                $s('type', ['target' => $t(['label' => 'Email:']), 'value' => 'ada@example.com']),
                $s('select', ['target' => $t(['label' => 'Contract']), 'value' => 'Full time']),
                $s('click', ['target' => $t(['role' => 'checkbox', 'name' => 'Remote friendly'])]),
                $s('type', ['target' => $t(['label' => 'Card number', 'frame' => 'iframe[title="Card"]']), 'value' => '4242 4242 4242 4242']),
                $s('click', ['target' => $t(['selector' => 'button.primary'])]),
                $s('click', ['target' => $t(['role' => 'button'])]),
                $s('press', ['value' => 'Enter']),
                $s('goto', ['url' => '/jobs']),
                $s('wait', ['target' => $t(['text' => 'Head of Support'])]),
                $s('click', ['target' => $t(['role' => 'button', 'name' => 'Menu']), 'only_on' => ['mobile']]),
            ]],
            ...$overrides,
        ]);
    }

    public function test_the_plan_becomes_a_pest_browser_test(): void
    {
        $test = PestTest::for($this->video());

        $this->assertStringContainsString("test('Post a recruiter\\'s job', function () {", $test);
        $this->assertStringContainsString(<<<'PHP'
    $page = visit('/jobs');

    $page
        ->click('Post a job')
        ->type('Job title', 'Head of Support\'s team')
        ->type('[placeholder="City"]', 'Accra')
        ->type('internal:text="Email:"s', 'ada@example.com')
        ->select('Contract', 'Full time')
        ->check('Remote friendly');

    $page->withinFrame('iframe[title="Card"]', function (AwaitableWebpage $frame) {
        $frame->type('Card number', '4242 4242 4242 4242');
    });

    $page
        ->click('css=button.primary');

    // Click role "button": give it a label, a name or a data-test attribute to find it by.

    $page
        ->keys(':focus', '{Enter}')
        ->navigate('/jobs')
        ->assertSee('Head of Support')
        ->assertNoJavaScriptErrors();
});
PHP, $test);
        $this->assertStringContainsString('use Pest\Browser\Api\AwaitableWebpage;', $test);
        // Captions, cards and steps for other screens are left out.
        $this->assertStringNotContainsString('The form', $test);
        $this->assertStringNotContainsString('Menu', $test);

        // It is valid PHP.
        File::put($file = sys_get_temp_dir().'/video-demo-pest-'.uniqid().'.php', $test);
        $this->assertTrue(Process::run([PHP_BINARY, '-l', $file])->successful());
        File::delete($file);
    }

    public function test_it_downloads_from_the_studio_and_exports_from_the_command_line(): void
    {
        $video = $this->video(['recordings' => ['desktop' => ['seconds' => 12.0, 'chapters' => []]]]);
        Storage::disk('local')->put($video->workFile('desktop'), 'mp4');

        // Offered with the video's other downloads.
        $this->get(route('video-demo.studio.show', $video))->assertSee('Pest browser test');
        $this->get(route('video-demo.studio.pest-test', $video))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.PestTest::fileName($video).'"')
            ->assertSee("visit('/jobs')", false);

        $out = sys_get_temp_dir().'/video-demo-pest-'.uniqid().'/PostAJobTest.php';
        $this->assertSame(0, Artisan::call('video-demo:export', ['slug' => $video->slug, '--format' => 'pest', '--out' => $out]));
        $this->assertStringContainsString("visit('/jobs')", File::get($out));
        File::deleteDirectory(dirname($out));

        // A video without a plan has no test to give.
        $empty = $this->video(['plan' => null]);
        $this->get(route('video-demo.studio.pest-test', $empty))->assertNotFound();
        $this->assertSame(1, Artisan::call('video-demo:export', ['slug' => $empty->slug, '--format' => 'pest']));
    }

    public function test_it_signs_in_as_the_kind_of_user_the_video_was_recorded_as(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class]);
        User::forceCreate(['email' => 'ada@example.com', 'password' => 'x', 'role' => 'recruiter']);

        // A role column: a factory user with that role.
        $test = PestTest::for($this->video(['record_as' => 'recruiter']));
        $this->assertStringContainsString('use Illuminate\\Foundation\\Auth\\User;', $test);
        $this->assertStringContainsString("\$this->actingAs(User::factory()->create(['role' => 'recruiter']));", $test);

        // A guest isn't signed in.
        $this->assertStringNotContainsString('actingAs', PestTest::for($this->video(['record_as' => 'guest'])));
    }
}
