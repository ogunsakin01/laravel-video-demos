<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Jobs\TranslateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\Spend;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Timeline;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Videos in other languages, made from the original's plan.
 */
class TranslateTest extends TestCase
{
    use FakesAnthropic;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function original(array $overrides = []): Video
    {
        return Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'version' => 1, 'language' => 'en', 'recordings' => ['desktop' => ['seconds' => 20, 'chapters' => []]],
            'style' => ['name' => 'Clean', 'direction' => 'Calm and clear.'], 'music' => null, 'max_cost' => 1.0,
            'plan' => ['title' => 'Post a job', 'summary' => 'From the jobs page to a live listing.', 'steps' => [
                ['action' => 'card', 'title' => 'Post a job', 'text' => 'In under a minute'],
                ['action' => 'goto', 'url' => '/jobs/create', 'chapter' => 'Fill it in'],
                ['action' => 'type', 'target' => ['role' => null, 'name' => null, 'label' => 'Job title', 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null], 'value' => 'Senior designer', 'say' => 'Give it a title people search for.'],
                ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'Publish job', 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null]],
            ]],
            ...$overrides,
        ]);
    }

    /**
     * The model's reply translating the original's words, keyed as the translator asks.
     */
    protected function translation(): array
    {
        return ['texts' => [
            ['id' => 'title', 'text' => 'Publier une offre'],
            ['id' => 'summary', 'text' => 'De la liste des offres à une annonce en ligne.'],
            ['id' => '0.title', 'text' => 'Publier une offre'],
            ['id' => '0.text', 'text' => 'En moins d’une minute'],
            ['id' => '1.chapter', 'text' => 'Remplissez-la'],
            ['id' => '2.value', 'text' => 'Designer senior'],
            ['id' => '2.say', 'text' => 'Donnez-lui un titre que les gens recherchent.'],
            ['id' => '9.say', 'text' => 'Not a step'],
        ]];
    }

    protected function reply(int $n, array $data): void
    {
        $this->respond($n, [['type' => 'text', 'text' => json_encode($data)]], 'end_turn', ['input_tokens' => 2_000, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], 'claude-sonnet-5-5');
    }

    /**
     * A recorder whose checks fail until $passes says the plan works, and
     * whose recordings always work; and an encoder that writes nothing real.
     *
     * @param  Closure(Plan): bool  $passes
     */
    protected function fakeBrowser(Closure $passes): object
    {
        $recorder = new class($passes) extends Recorder
        {
            public array $locales = [];

            public function __construct(public Closure $passes) {}

            public function verify(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
            {
                $this->locales[] = ['verify', $this->locale];

                return ($this->passes)($plan)
                    ? RecordingResult::fromArray(['ok' => true], $device)
                    : RecordingResult::fromArray(['ok' => false, 'failedStep' => 3, 'error' => 'Could not find a visible element with label "Job title".', 'url' => 'http://app.test/jobs/create', 'snapshot' => '- textbox "Intitulé du poste"'], $device);
            }

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                $this->locales[] = ['record', $this->locale];
                File::ensureDirectoryExists($dir);
                File::put("$dir/raw.webm", 'webm');

                return RecordingResult::fromArray(['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 21_000, 'chapters' => []], $device);
            }
        };
        $this->app->instance(Recorder::class, $recorder);
        $this->app->instance(Encoder::class, new class extends Encoder
        {
            public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1, ?Timeline $timeline = null): void
            {
                File::put($mp4, 'mp4');
                File::put($poster, 'jpg');
            }
        });

        return $recorder;
    }

    public function test_a_video_is_made_in_another_language_from_its_plan(): void
    {
        $this->startFakeAnthropic();
        try {
            $this->reply(1, $this->translation());
            // The app's labels are translated too: the field is found again on the French page.
            $this->reply(2, ['target' => ['role' => '', 'name' => '', 'label' => 'Intitulé du poste', 'placeholder' => '', 'text' => '', 'selector' => '', 'frame' => ''], 'value' => 'Designer senior']);
            $recorder = $this->fakeBrowser(fn (Plan $plan) => $plan->steps[2]['target']['label'] === 'Intitulé du poste');
            $original = $this->original();

            $french = VideoDemo::translate($original, 'fr', ['queue' => false]);

            $this->assertSame(VideoStatus::Ready, $french->status, (string) $french->error);
            $this->assertSame('post-a-job-fr', $french->slug);
            $this->assertSame('fr', $french->language);
            $this->assertTrue($french->source->is($original));
            $this->assertSame('Publier une offre', $french->title);

            $plan = $french->plan;
            $this->assertSame(['Publier une offre', 'En moins d’une minute'], [$plan['steps'][0]['title'], $plan['steps'][0]['text']]);
            $this->assertSame('Remplissez-la', $plan['steps'][1]['chapter']);
            $this->assertSame('/jobs/create', $plan['steps'][1]['url']);
            $this->assertSame(['Designer senior', 'Donnez-lui un titre que les gens recherchent.', 'Intitulé du poste'], [$plan['steps'][2]['value'], $plan['steps'][2]['say'], $plan['steps'][2]['target']['label']]);
            // A target the model wasn't asked about stays as it was.
            $this->assertSame('Publish job', $plan['steps'][3]['target']['name']);
            // The original is untouched.
            $this->assertSame('Job title', $original->fresh()->plan['steps'][2]['target']['label']);

            // Checked, fixed, checked again and recorded, all with the app in French.
            $this->assertSame([['verify', 'fr'], ['verify', 'fr'], ['record', 'fr']], $recorder->locales);
            $this->assertNull($recorder->locale);

            // Only the words went to the model, never a target.
            $sent = $this->request(1)['body']['messages'][0]['content'];
            $this->assertStringContainsString('"id": "2.say"', $sent);
            $this->assertStringNotContainsString('Publish job', $sent);
            $this->assertStringContainsString('into French', json_encode($this->request(1)['body']['system']));
            $this->assertStringContainsString('textbox "Intitulé du poste"', $this->request(2)['body']['messages'][0]['content']);

            $this->assertGreaterThan(0, $french->cost);
            $this->assertSame(2, Spend::where('kind', 'translate')->where('video_id', $french->id)->count());
            $this->assertSame(0.0, $original->fresh()->cost);
        } finally {
            $this->stopFakeAnthropic();
        }
    }

    public function test_a_step_that_cant_be_found_after_a_few_tries_fails_with_how_to_fix_it(): void
    {
        $this->startFakeAnthropic();
        try {
            $this->reply(1, $this->translation());
            foreach (range(2, 6) as $n) {
                $this->reply($n, ['target' => ['role' => '', 'name' => '', 'label' => 'Toujours pas', 'placeholder' => '', 'text' => '', 'selector' => '', 'frame' => ''], 'value' => '']);
            }
            $this->fakeBrowser(fn () => false);

            $french = VideoDemo::translate($this->original(), 'fr', ['queue' => false]);

            $this->assertSame(VideoStatus::Failed, $french->status);
            $this->assertStringContainsString('With the app in French, the plan doesn\'t work as it is. Open the video, edit the plan, then record it again.', $french->error);
            $this->assertTrue($this->sent(1 + TranslateVideo::MAX_FIXES));
            $this->assertFalse($this->sent(2 + TranslateVideo::MAX_FIXES));
        } finally {
            $this->stopFakeAnthropic();
        }
    }

    public function test_translating_explains_what_it_cant_do(): void
    {
        $original = $this->original();

        foreach ([
            '12' => "isn't a language code",
            'en-GB' => 'already in English',
        ] as $language => $message) {
            try {
                VideoDemo::translate($original, $language);
                $this->fail("[$language] was accepted.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        Queue::fake();
        $french = VideoDemo::translate($original, 'fr');
        Queue::assertPushed(TranslateVideo::class);
        $this->assertSame(VideoStatus::Queued, $french->status);

        $this->expectExceptionMessage('There is already a French version: post-a-job-fr.');
        VideoDemo::translate($original, 'fr-CA');
    }

    public function test_a_translation_can_not_be_translated(): void
    {
        $french = $this->original(['slug' => 'post-a-job-fr', 'language' => 'fr', 'source_video_id' => $this->original()->id]);

        $this->expectExceptionMessage('This video is itself a translation.');
        VideoDemo::translate($french, 'es');
    }

    public function test_the_studio_adds_a_language_and_links_the_versions(): void
    {
        Queue::fake();
        $original = $this->original();

        $this->get("/video-demo/studio/{$original->id}")->assertSee('Other languages')->assertSee('Make it in this language');

        $this->post("/video-demo/studio/{$original->id}/translations", ['language' => 'es'])->assertRedirect();
        $spanish = Video::where('source_video_id', $original->id)->firstOrFail();
        $this->assertSame('es', $spanish->language);
        Queue::assertPushed(TranslateVideo::class);

        $this->get("/video-demo/studio/{$original->id}")->assertSee('Spanish');
        $this->get("/video-demo/studio/{$spanish->id}")->assertSee('The Spanish version of')->assertSee('Post a job');
        $this->get('/video-demo/studio')->assertSee('Spanish version');
    }

    public function test_the_command_makes_and_publishes_versions(): void
    {
        $this->startFakeAnthropic();
        try {
            $this->reply(1, $this->translation());
            $this->fakeBrowser(fn () => true);
            $this->original();

            $this->assertSame(0, Artisan::call('video-demo:translate', ['slug' => 'post-a-job', 'languages' => ['fr'], '--publish' => true, '--json' => true]));
            $json = json_decode(Artisan::output(), true);

            $this->assertSame('post-a-job', $json['source']);
            $this->assertSame(['fr', 'post-a-job-fr', 'ready', true], [$json['videos'][0]['language'], $json['videos'][0]['slug'], $json['videos'][0]['status'], $json['videos'][0]['published']]);
            $this->assertSame('post-a-job', app(Manifest::class)->find('post-a-job-fr', 'public')['translation_of']);
        } finally {
            $this->stopFakeAnthropic();
        }
    }

    public function test_the_player_and_the_library_show_each_viewer_their_language(): void
    {
        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo(['language' => 'en', 'routes' => [['name' => 'jobs.create', 'uri' => 'jobs/create']]]));
        $manifest->put($this->manifestVideo(['slug' => 'post-a-job-fr', 'title' => 'Publier une offre', 'language' => 'fr', 'translation_of' => 'post-a-job', 'video' => 'video-demo/post-a-job-fr-v1.mp4']));

        // The library lists the original once.
        $this->assertSame(['post-a-job'], array_column($manifest->forArea('public'), 'slug'));
        $this->assertSame(['post-a-job-fr'], array_column($manifest->translations('post-a-job', 'public'), 'slug'));

        app()->setLocale('fr_FR');
        $this->assertStringContainsString('post-a-job-fr-v1.mp4', Blade::render('<x-video-demo::player slug="post-a-job" />'));
        $this->assertStringContainsString('post-a-job-v1.mp4', Blade::render('<x-video-demo::player slug="post-a-job" :language="false" />'));
        $this->assertStringContainsString('post-a-job-v1.mp4', Blade::render('<x-video-demo::player slug="post-a-job" language="en" />'));
        $this->assertStringContainsString('srclang="fr"', Blade::render('<x-video-demo::player slug="post-a-job" />').'srclang="fr"');
        $this->get('/help/videos')->assertSee('Publier une offre')->assertDontSee('>Post a job<', false);
        $this->get('/help/videos/post-a-job')->assertSee('Publier une offre')->assertSee('Français')->assertSee('English');

        app()->setLocale('de');
        $this->assertStringContainsString('post-a-job-v1.mp4', Blade::render('<x-video-demo::player slug="post-a-job" />'));
        $this->get('/help/videos/post-a-job?lang=fr')->assertSee('Publier une offre');
        $this->get('/help/videos/post-a-job-fr?lang=en')->assertSee('Post a job');
        $this->get('/help/videos/post-a-job-fr')->assertSee('Publier une offre');
    }

    public function test_the_recorder_asks_for_the_app_in_the_videos_language(): void
    {
        Route::get('/_locale', fn () => app()->getLocale());

        $this->withUnencryptedCookie(Recorder::LOCALE_COOKIE, '../../etc')->get('/_locale')->assertSee('en');
        $this->withUnencryptedCookie(Recorder::LOCALE_COOKIE, 'pt-BR')->get('/_locale')->assertSee('pt_BR');
    }
}
