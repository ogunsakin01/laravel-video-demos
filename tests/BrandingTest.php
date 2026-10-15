<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * A style's logo on every frame, and its end card after the last step.
 */
class BrandingTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-brand-'.uniqid();
        config(['filesystems.disks.local.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function form(array $overrides = []): array
    {
        return ['name' => 'Branded', 'direction' => 'Calm.', 'theme' => 'light', 'accent' => '#e5693d', 'captions' => 'bottom', 'pace' => 'normal', 'art' => 'none', ...$overrides];
    }

    public function test_a_style_takes_a_logo_and_an_end_card(): void
    {
        $this->post('/video-demo/studio/styles', $this->form([
            'logo' => UploadedFile::fake()->image('logo.png', 120, 40),
            'logo_position' => 'bottom-left',
            'end_title' => 'Try it at example.com',
            'end_text' => 'Free for 14 days.',
        ]))->assertRedirect();

        $style = VideoStyle::where('name', 'Branded')->firstOrFail();
        $this->assertSame('bottom-left', $style->logo_position);
        $this->assertTrue(Storage::disk('local')->exists($style->logo));
        $this->assertSame(['Try it at example.com', 'Free for 14 days.'], [$style->end_title, $style->end_text]);
        $this->assertSame([$style->logo, 'bottom-left', 'Try it at example.com', 'Free for 14 days.'], array_values(array_intersect_key($style->snapshot(), array_flip(['logo', 'logo_position', 'end_title', 'end_text']))));
        $this->assertStringStartsWith('data:image/png;base64,', VideoStyle::logoSource($style->snapshot()));

        $this->get('/video-demo/studio/styles')->assertSee('Logo bottom left')->assertSee('Ends with “Try it at example.com”', false);
        $this->get("/video-demo/studio/styles/{$style->id}/logo")->assertOk();

        // Saving without a new file keeps the logo; "Remove" takes it off.
        $this->put("/video-demo/studio/styles/{$style->id}", $this->form(['logo_position' => 'top-right']))->assertRedirect();
        $this->assertSame('top-right', $style->fresh()->logo_position);
        $this->assertNotNull($style->fresh()->logo);
        $this->put("/video-demo/studio/styles/{$style->id}", $this->form(['remove_logo' => '1', 'logo_position' => 'top-right']))->assertRedirect();
        $this->assertNull($style->fresh()->logo);
        $this->assertSame('none', $style->fresh()->logo_position);
        $this->assertNull(VideoStyle::logoSource($style->fresh()->snapshot()));
        $this->get("/video-demo/studio/styles/{$style->id}/logo")->assertNotFound();

        $this->post('/video-demo/studio/styles', $this->form(['name' => 'Too big', 'logo' => UploadedFile::fake()->create('logo.png', 2048, 'image/png')]))->assertSessionHasErrors('logo');
        $this->post('/video-demo/studio/styles', $this->form(['name' => 'Not an image', 'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('logo');
    }

    public function test_the_end_card_is_added_when_recording_only(): void
    {
        $plan = new Plan('x', 'y', [['action' => 'goto', 'url' => '/']]);

        $this->assertSame($plan, $plan->withEndCard(['end_title' => '  ']));
        $ended = $plan->withEndCard(['end_title' => 'Try it at example.com', 'end_text' => '']);
        $this->assertSame(['goto', 'card'], array_column($ended->steps, 'action'));
        $this->assertSame(['Try it at example.com', null, 3500], [$ended->steps[1]['title'], $ended->steps[1]['text'], $ended->steps[1]['ms']]);
        $this->assertSame([], $ended->problems());
    }

    public function test_the_recorder_gets_the_logo_and_never_shows_it_on_a_check(): void
    {
        Storage::disk('local')->put('video-demo/logos/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $jobs = new \ArrayObject;
        $recorder = new class($jobs) extends Recorder
        {
            public function __construct(public \ArrayObject $jobs) {}

            protected function run(string $script, string $job, int $timeout, ?Closure $shouldStop = null): string
            {
                $this->jobs[] = json_decode(File::get($job), true);
                File::put(dirname($job).'/result.json', json_encode(['ok' => true, 'durationMs' => 1000]));

                return '';
            }
        };
        $style = ['theme' => 'dark', 'logo' => 'video-demo/logos/a.svg', 'logo_position' => 'top-left', 'end_title' => 'Ignored here'];
        $plan = new Plan('x', 'y', [['action' => 'goto', 'url' => '/']]);

        $recorder->record($plan, $this->root.'/rec', $style);
        $recorder->verify($plan, $this->root.'/check', $style);

        $this->assertSame('top-left', $jobs[0]['style']['logo_position']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $jobs[0]['style']['logo_src']);
        $this->assertArrayNotHasKey('end_title', $jobs[0]['style']);
        $this->assertSame(['app_theme' => 'auto'], $jobs[1]['style']);
    }
}
