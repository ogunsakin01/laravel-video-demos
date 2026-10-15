<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\Narrator;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\SoundController;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Timeline;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;
use Ogunsakin01\VideoDemo\VideoDemo;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Soundtrack;
use Ogunsakin01\VideoDemo\Voice\Speaker;
use Ogunsakin01\VideoDemo\Voice\Voices;
use Ogunsakin01\VideoDemo\Voice\Wav;
use RuntimeException;

/**
 * Voice-over and background music: the voices offered, the clips, the mix,
 * subtitles, the forms and how a video is made with them.
 */
class VoiceTest extends TestCase
{
    use FakesAnthropic;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-voice-'.uniqid();
        config([
            'filesystems.disks.local.root' => $this->root,
            'video-demo.recorder.node_modules' => $this->root.'/recorder/node_modules',
            'video-demo.voice.cache_path' => $this->root.'/model',
            // Most tests are about your own tracks; one is about the built-in ones.
            'video-demo.music.builtin' => false,
        ]);
        // No computer's voice, unless a test adds one.
        Voices::flush([]);
    }

    protected function tearDown(): void
    {
        Voices::flush();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** Pretend the local voice is installed. */
    protected function installLocalVoice(): void
    {
        File::ensureDirectoryExists($this->root.'/recorder/node_modules/kokoro-js');
        File::put($this->root.'/recorder/node_modules/kokoro-js/package.json', '{}');
    }

    /** A 16-bit stereo WAV at 44.1 kHz of the given length and level, with an extra chunk before the data. */
    protected function wav(string $file, float $seconds, int $level = 8000): string
    {
        $frames = (int) round($seconds * 44100);
        $data = str_repeat(pack('vv', $level & 0xFFFF, $level & 0xFFFF), $frames);
        $list = 'LIST'.pack('V', 4).'INFO';
        File::ensureDirectoryExists(dirname($file));
        File::put($file, 'RIFF'.pack('V', 4 + 24 + strlen($list) + 8 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 2, 44100, 44100 * 4, 4, 16).$list.'data'.pack('V', strlen($data)).$data);

        return $file;
    }

    protected function upload(string $name): string
    {
        return Music::upload(UploadedFile::fake()->createWithContent($name, (string) file_get_contents($this->wav($this->root."/src/$name", 1))));
    }

    public function test_voices_offer_only_what_works_here(): void
    {
        $this->assertSame([], Voices::options());
        $this->assertFalse(Voices::available('kokoro:af_heart'));

        $this->installLocalVoice();
        Voices::flush(['Samantha' => 'Samantha (en US)']);

        $options = Voices::options();
        $this->assertSame(['Local voices, free', "This computer's voice: robotic, for drafts only"], array_keys($options));
        $this->assertSame('Heart, warm (US)', $options['Local voices, free']['kokoro:af_heart']);
        $this->assertTrue(Voices::available('system:Samantha'));
        $this->assertSame(['No voice', 'Heart, warm (US)', 'kokoro:nobody'], [Voices::label(null), Voices::label('kokoro:af_heart'), Voices::label('kokoro:nobody')]);
        $this->assertSame(['kokoro', 'af_heart'], Voices::parse('kokoro:af_heart'));
        // The model is kept outside the project.
        $this->assertSame($this->root.'/model', Voices::modelCache());
    }

    public function test_wav_lengths_are_read_past_extra_chunks(): void
    {
        $this->assertSame(1.5, Wav::seconds($this->wav($this->root.'/a.wav', 1.5)));

        File::put($this->root.'/b.wav', 'not a wav');
        $this->expectException(RuntimeException::class);
        Wav::seconds($this->root.'/b.wav');
    }

    public function test_the_mixer_places_lines_and_ducks_the_music(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        $line = $this->wav($this->root.'/line.wav', 1, 8000);
        $quiet = $this->wav($this->root.'/quiet.wav', 1, 1000);
        $music = $this->wav($this->root.'/music.wav', 3, 10000);
        File::ensureDirectoryExists($this->root.'/mix');
        File::put($this->root.'/mix/job.json', json_encode(['seconds' => 10, 'out' => $this->root.'/out.wav', 'clips' => [['file' => $line, 'at' => 4], ['file' => $quiet, 'at' => 6]], 'music' => ['file' => $music, 'volume' => 0.25]]));

        Process::run(['node', Recorder::script('mix.cjs'), $this->root.'/mix/job.json']);
        $this->assertSame(['ok' => true], json_decode((string) File::get($this->root.'/mix/result.json'), true));
        $this->assertEqualsWithDelta(10.0, Wav::seconds($this->root.'/out.wav'), 0.01);

        $samples = unpack('v*', substr((string) File::get($this->root.'/out.wav'), 44));
        $at = fn (float $t) => (int) round(abs(($samples[(int) ($t * 44100) * 2 + 1] + 32768) % 65536 - 32768));

        // Fades in, plays at its volume (looping past its own 3 seconds), and fades out.
        $this->assertLessThan(500, $at(0.02));
        $this->assertEqualsWithDelta(2500, $at(3.5 - 0.5), 30);
        $this->assertLessThan(200, $at(9.98));
        // Every line is brought to the same loudness (a loud one down), with the music a quarter as loud under it.
        $this->assertEqualsWithDelta(6000 + 625, $at(4.5), 60);
        // A very quiet one is raised at most four times, so noise isn't blown up.
        $this->assertEqualsWithDelta(4000 + 625, $at(6.5), 60);
        $this->assertEqualsWithDelta(2500, $at(7.75), 30);
    }

    public function test_subtitles_are_written_from_the_spoken_lines(): void
    {
        (new Soundtrack(new Encoder))->subtitles([
            ['text' => 'Open Post a job.', 'at' => 1.15, 'seconds' => 2.4],
            ['text' => 'Publish it.', 'at' => 62.5, 'seconds' => 1],
        ], $this->root.'/s.vtt');

        $this->assertSame("WEBVTT\n\n1\n00:00:01.150 --> 00:00:03.550\nOpen Post a job.\n\n2\n00:01:02.500 --> 00:01:03.500\nPublish it.\n", File::get($this->root.'/s.vtt'));
    }

    public function test_plans_hold_each_spoken_step_until_its_line_is_finished(): void
    {
        $plan = Plan::fromArray(['title' => 'x', 'summary' => '', 'steps' => [
            ['action' => 'goto', 'url' => '/jobs', 'say' => 'Open the jobs page.'],
            ['action' => 'click', 'target' => ['text' => 'Post'], 'say' => ''],
            ['action' => 'caption', 'title' => 'Done', 'ms' => 1000, 'say' => 'Open the jobs page.'],
        ]]);

        $this->assertNull($plan->steps[1]['say']);
        $this->assertSame(['Open the jobs page.'], $plan->lines());
        $this->assertContains('say', Plan::schema()['properties']['steps']['items']['required']);

        // Held while recording for the line plus a gap, at the playback speed.
        $held = $plan->withHolds(['Open the jobs page.' => 1.5], 2);
        $this->assertSame([4000, null, 4000], array_map(fn ($s) => $s['hold_ms'] ?? null, $held->steps));

        // A line takes time once its clip is made; without a voice it takes none.
        $voiced = Plan::fromArray(['title' => 'x', 'summary' => '', 'steps' => [['action' => 'caption', 'title' => 'Done', 'ms' => 1000, 'say' => 'Hello there.', 'voice_ms' => 3000]]]);
        $this->assertSame(3.0, $voiced->estimatedSeconds());
        $this->assertSame(1.0, $voiced->withoutVoice()->estimatedSeconds());
    }

    public function test_recording_results_pair_each_line_with_when_its_step_started(): void
    {
        $result = RecordingResult::fromArray(['ok' => true, 'lines' => ['Open it.', null, ' Save it. '], 'starts' => [0.5, 2.0, 4.25]]);

        $this->assertSame([['text' => 'Open it.', 'at' => 0.5], ['text' => 'Save it.', 'at' => 4.25]], $result->spoken);
    }

    public function test_the_narrator_writes_lines_for_a_plan_without_them(): void
    {
        $this->startFakeAnthropic();
        try {
            $this->respond(1, [['type' => 'text', 'text' => json_encode(['lines' => [['step' => 2, 'say' => 'Open Post a job.'], ['step' => 9, 'say' => 'Not a step.']]])]], 'end_turn',
                ['input_tokens' => 2_000, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], 'claude-sonnet-5-5');
            $video = Video::create(['prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Recording, 'style' => ['name' => 'Clean', 'direction' => 'Calm and clear.']]);
            $plan = Plan::fromArray(['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/jobs'], ['action' => 'click', 'target' => ['role' => 'link', 'name' => 'Post a job']]]]);

            $narrated = app(Narrator::class)->narrate($plan, $video);

            $this->assertSame(['Open Post a job.'], $narrated->lines());
            $this->assertSame('Open Post a job.', $narrated->steps[1]['say']);
            $this->assertEqualsWithDelta(0.007, $video->fresh()->cost, 0.0001);
            $sent = $this->request(1)['body']['messages'][0]['content'];
            $this->assertStringContainsString('2. click link Post a job', $sent);
            $this->assertStringContainsString('Calm and clear.', $sent);
        } finally {
            $this->stopFakeAnthropic();
        }
    }

    /**
     * Stand-ins for the voice, the browser, ffmpeg and the mixer, so a video
     * can be made anywhere.
     *
     * @return array{recorder: Recorder, soundtrack: Soundtrack, encoder: Encoder}
     */
    protected function fakeMaking(?Closure $speak = null): array
    {
        $this->app->instance(Speaker::class, new class($speak) extends Speaker
        {
            public function __construct(protected ?Closure $fake) {}

            public function speak(string $voice, array $lines, bool $mixable = true, string $tone = ''): array
            {
                if ($this->fake) {
                    ($this->fake)();
                }

                return collect($lines)->mapWithKeys(fn ($l) => [$l => ['file' => "/clips/$l.wav", 'seconds' => 2.0]])->all();
            }
        });
        $recorder = new class extends Recorder
        {
            public array $voices = [];

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                $this->voices[] = $voice;
                File::ensureDirectoryExists($dir);
                File::put("$dir/raw.webm", 'webm');
                $plan = $plan->forDevice($device);

                return RecordingResult::fromArray(['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 9000,
                    'lines' => array_map(fn ($s) => $s['say'] ?? null, $plan->steps), 'starts' => [0.5, 3.0]], $device);
            }
        };
        $soundtrack = new class(new Encoder) extends Soundtrack
        {
            public array $mixes = [];

            public function mix(array $clips, ?array $music, float $seconds, string $wav): void
            {
                $this->mixes[] = compact('clips', 'music', 'seconds');
                File::put($wav, 'wav');
            }
        };
        $encoder = new class extends Encoder
        {
            public int $sound = 0;

            public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1, ?Timeline $timeline = null): void
            {
                File::put($mp4, 'mp4');
                File::put($poster, 'jpg');
            }

            public function addAudio(string $mp4, string $wav): void
            {
                $this->sound++;
            }
        };
        $this->app->instance(Recorder::class, $recorder);
        $this->app->instance(Soundtrack::class, $soundtrack);
        $this->app->instance(Encoder::class, $encoder);

        return compact('recorder', 'soundtrack', 'encoder');
    }

    protected function voicedVideo(array $overrides = []): Video
    {
        return Video::create([
            'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Queued, 'devices' => ['desktop'], 'voice' => 'kokoro:af_heart', 'music' => 'calm',
            'plan' => ['title' => 'Post a job', 'summary' => '', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs', 'say' => 'Open the jobs page.'],
                ['action' => 'click', 'target' => ['text' => 'Post'], 'say' => 'Then choose Post a job.'],
            ]],
            ...$overrides,
        ]);
    }

    public function test_a_video_is_made_with_its_voice_over_and_music(): void
    {
        $fakes = $this->fakeMaking();
        $this->upload('calm.wav');
        $video = $this->voicedVideo();

        GenerateVideo::dispatchSync($video, replan: false);
        $video->refresh();

        $this->assertSame(VideoStatus::Ready, $video->status, (string) $video->error);
        // Each step was held for its line: the recorder knew how long each one takes.
        $this->assertSame([['Open the jobs page.' => 2.0, 'Then choose Post a job.' => 2.0]], $fakes['recorder']->voices);
        // Each clip starts just after its step does, over the music at its default volume.
        $mix = $fakes['soundtrack']->mixes[0];
        $this->assertSame([['file' => '/clips/Open the jobs page..wav', 'at' => 0.65], ['file' => '/clips/Then choose Post a job..wav', 'at' => 3.15]], $mix['clips']);
        $this->assertSame(0.25, $mix['music']['volume']);
        $this->assertSame(1, $fakes['encoder']->sound);
        $this->assertStringContainsString("00:00:00.650 --> 00:00:02.650\nOpen the jobs page.", Storage::disk('local')->get($video->workFile('desktop', 'subtitles')));
        $this->assertContains('Speaking the voice-over: 2 lines in Heart, warm (US)', array_column($video->log, 'message'));
        $this->assertSame(2500, $video->plan['steps'][0]['voice_ms']);
    }

    public function test_a_voice_that_fails_leaves_the_video_silent_but_made(): void
    {
        $fakes = $this->fakeMaking(fn () => throw new RuntimeException('The local voice is not installed.'));
        $video = $this->voicedVideo(['music' => null]);

        GenerateVideo::dispatchSync($video, replan: false);
        $video->refresh();

        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame([[]], $fakes['recorder']->voices);
        $this->assertSame([], $fakes['soundtrack']->mixes);
        $this->assertContains("The voice-over couldn't be made, so the video is made without it: The local voice is not installed.", array_column($video->log, 'message'));
        $this->assertFalse(Storage::disk('local')->exists($video->workFile('desktop', 'subtitles')));
    }

    public function test_the_forms_save_a_voice_and_music_that_work_here(): void
    {
        Queue::fake();
        $this->installLocalVoice();
        $this->upload('Calm Pad.wav');
        $form = ['style_id' => VideoStyle::options()->first()->id, 'devices' => ['desktop'], 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'visibility' => 'public'];

        $this->get('/video-demo/studio/create')->assertSee('Voice-over')->assertSee('Heart, warm (US)')->assertSee('Calm Pad')->assertSee('Play sample');

        $this->post('/video-demo/studio', [...$form, 'voice' => 'kokoro:af_heart', 'music' => 'calm-pad', 'music_volume' => 40])->assertRedirect();
        $video = Video::latest('id')->first();
        $this->assertSame(['kokoro:af_heart', 'calm-pad', 0.4], [$video->voice, $video->music, $video->music_volume]);

        // A voice that isn't available here is refused.
        $this->post('/video-demo/studio', [...$form, 'voice' => 'kokoro:nobody'])->assertSessionHasErrors('voice');
        $this->post('/video-demo/studio', [...$form, 'music' => 'nope'])->assertSessionHasErrors('music');

        // Make it again can change them, and keeps the plan: they need no new plan.
        $video->update(['status' => VideoStatus::Ready, 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/']]]]);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Heart, warm (US)')->assertSee('Calm Pad');
        $this->post("/video-demo/studio/{$video->id}/again", ['prompt' => $video->prompt, 'target_seconds' => 30, 'devices' => ['desktop'], 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'sample_data' => '0', 'record_as' => '', 'voice' => '', 'music' => 'calm-pad', 'music_volume' => 20, 'new_plan' => '0'])->assertRedirect();
        $this->assertSame([null, 0.2], [$video->fresh()->voice, $video->fresh()->music_volume]);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => ! $job->replan);

        $this->getJson('/video-demo/studio/voice-sample?voice=kokoro:nobody')->assertNotFound();
    }

    public function test_music_tracks_are_uploaded_played_and_deleted(): void
    {
        $this->post('/video-demo/studio/music', ['track' => UploadedFile::fake()->createWithContent('Upbeat Corporate.wav', (string) file_get_contents($this->wav($this->root.'/u.wav', 1)))])
            ->assertRedirect('/video-demo/studio/styles#music');
        $this->assertSame(['upbeat-corporate' => 'Upbeat Corporate'], Music::options());

        $this->get('/video-demo/studio/music/upbeat-corporate')->assertOk();
        $this->get('/video-demo/studio/styles')->assertSee('Upbeat Corporate')->assertSee('Add a track');

        $this->post('/video-demo/studio/music', ['track' => UploadedFile::fake()->create('notes.txt', 1)])->assertSessionHasErrors('track');

        $this->delete('/video-demo/studio/music/upbeat-corporate')->assertRedirect();
        $this->assertSame([], Music::options());
        $this->get('/video-demo/studio/music/upbeat-corporate')->assertNotFound();
    }

    public function test_built_in_tracks_are_offered_and_downloaded_when_first_used(): void
    {
        config(['video-demo.music.builtin' => true, 'video-demo.music.builtin_url' => 'https://cdn.example.com/music']);
        Http::fake([
            'cdn.example.com/music/calm.mp3' => Http::response('ID3calm'),
            'cdn.example.com/*' => Http::response('', 503),
            // GitHub, when the first address fails.
            'raw.githubusercontent.com/*/corporate.mp3' => Http::response('ID3corporate'),
            'raw.githubusercontent.com/*' => Http::response('', 404),
        ]);
        $this->upload('brand.wav');

        // Your own tracks first, then the built-in ones.
        $this->assertSame(['brand', 'builtin:calm', 'builtin:upbeat', 'builtin:corporate', 'builtin:cinematic'], array_keys(Music::options()));
        $this->assertSame('Calm (built in)', Music::label('builtin:calm'));
        $this->assertFileDoesNotExist(Music::find('builtin:calm')['file']);

        $file = Music::local('builtin:calm');
        $this->assertSame('ID3calm', File::get($file));
        // Downloaded once, then kept.
        Music::local('builtin:calm');
        Http::assertSentCount(1);
        $this->get('/video-demo/studio/music/builtin:calm')->assertOk();

        $this->assertSame('ID3corporate', File::get(Music::local('builtin:corporate')));
        $this->getJson('/video-demo/studio/music/builtin:upbeat')->assertStatus(502)->assertJsonPath('error', "The built-in track [upbeat] couldn't be downloaded: HTTP 503 from https://cdn.example.com/music; HTTP 404 from ".Music::BUILT_IN_FALLBACK_URL.'.');
        $this->assertFileDoesNotExist(Music::find('builtin:upbeat')['file'].'.part');
        // Built-in tracks can't be deleted.
        $this->delete('/video-demo/studio/music/builtin:calm');
        $this->assertFileExists($file);
    }

    public function test_the_facade_and_the_command_take_a_voice_and_music(): void
    {
        Queue::fake();
        $this->installLocalVoice();
        $this->upload('calm.wav');
        $demo = app(VideoDemo::class);

        $video = $demo->make('x', ['voice' => 'kokoro:bf_emma', 'music' => 'calm', 'music_volume' => 0.3]);
        $this->assertSame(['kokoro:bf_emma', 'calm', 0.3], [$video->voice, $video->music, $video->music_volume]);
        $this->assertArrayHasKey('kokoro:bf_emma', $demo->voices());
        $this->assertSame(['calm' => 'Calm'], $demo->music());

        // The config's default voice, unless "none" is asked for.
        config(['video-demo.voice.default' => 'kokoro:af_heart']);
        $this->assertSame('kokoro:af_heart', $demo->make('y')->voice);
        $this->artisan('video-demo:generate', ['description' => 'x', '--voice' => 'none', '--queue' => true, '--json' => true])->assertSuccessful();
        $this->assertNull(Video::latest('id')->first()->voice);

        // A style's track that isn't here is left out rather than refused.
        config(['video-demo.music.builtin' => false]);
        $this->assertNull($demo->make('w', ['style' => 'promo', 'voice' => null])->music);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The voice [kokoro:nobody] isn't available here.");
        $demo->make('z', ['voice' => 'kokoro:nobody']);
    }

    public function test_voices_know_the_languages_they_speak(): void
    {
        Voices::flush(['Amélie' => 'Amélie (fr CA)', 'Samantha' => 'Samantha (en US)'], ['Amélie' => 'fr-CA', 'Samantha' => 'en-US']);

        $this->assertSame(['en'], Voices::languages('kokoro:af_heart'));
        $this->assertSame(['fr'], Voices::languages('system:Amélie'));
        $this->assertSame(['*'], Voices::languages('openai:marin'));
        $this->assertTrue(Voices::speaks('system:Amélie', 'fr-FR'));
        $this->assertFalse(Voices::speaks('system:Samantha', 'fr'));
        $this->assertFalse(Voices::speaks('kokoro:af_heart', 'de'));
        $this->assertTrue(Voices::speaks('gemini:Kore', 'ja'));

        // ElevenLabs speaks many languages, unless its model is an English-only one.
        $this->assertTrue(Voices::speaks('elevenlabs:abc', 'es'));
        config(['video-demo.voice.providers.elevenlabs.model' => 'eleven_turbo_v2']);
        $this->assertFalse(Voices::speaks('elevenlabs:abc', 'es'));
    }

    public function test_a_video_is_made_in_the_language_of_the_pages_and_skips_a_voice_that_cannot_speak_it(): void
    {
        config(['video-demo.recorder.check_pages' => true, 'app.locale' => 'en']);
        $fakes = $this->fakeMaking();
        $checker = new class extends Recorder
        {
            public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
            {
                $this->pageLanguages = ['/jobs' => 'fr-FR'];

                return [];
            }

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                File::ensureDirectoryExists($dir);
                File::put("$dir/raw.webm", 'webm');

                return RecordingResult::fromArray(['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 9000, 'lines' => [], 'starts' => []], $device);
            }
        };
        $this->app->instance(Recorder::class, $checker);
        $video = $this->voicedVideo(['music' => null]);

        GenerateVideo::dispatchSync($video, replan: false);
        $video->refresh();

        $this->assertSame(VideoStatus::Ready, $video->status, (string) $video->error);
        $this->assertSame('fr-FR', $video->page_language);
        $this->assertSame('fr-FR', $video->languageCode());
        $log = array_column($video->log, 'message');
        $this->assertContains('The pages are in French (France), so the video is made in French (France)', $log);
        // The local voice speaks only English, so the video has no voice-over rather than a mangled one.
        $this->assertContains("Heart, warm (US) doesn't speak French (France), so the video is made without a voice-over. Choose a voice that does.", $log);
        $this->assertArrayNotHasKey('voice_ms', $video->plan['steps'][0]);
        $this->assertSame([], $fakes['soundtrack']->mixes);
    }

    public function test_the_forms_take_a_language_and_list_the_voices_that_speak_it(): void
    {
        Queue::fake();
        $this->installLocalVoice();
        Voices::flush(['Amélie' => 'Amélie (fr CA)'], ['Amélie' => 'fr-CA']);
        config(['app.locale' => 'de']);
        $form = ['style_id' => VideoStyle::options()->first()->id, 'devices' => ['desktop'], 'prompt' => 'Post a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'visibility' => 'public'];

        $this->get('/video-demo/studio/create')
            ->assertSee('Same as the app (German)')
            ->assertSee('data-languages="en"', false)
            ->assertSee('data-languages="fr"', false);

        $this->post('/video-demo/studio', [...$form, 'language' => 'fr', 'voice' => 'system:Amélie'])->assertRedirect();
        $video = Video::latest('id')->first();
        $this->assertSame(['fr', 'system:Amélie'], [$video->language, $video->voice]);

        // A voice that doesn't speak the chosen language is refused, and so is an unknown language.
        $this->post('/video-demo/studio', [...$form, 'language' => 'fr', 'voice' => 'kokoro:af_heart'])->assertSessionHasErrors(['voice' => "Heart, warm (US) doesn't speak French. Choose a voice that does, or no voice."]);
        $this->post('/video-demo/studio', [...$form, 'language' => 'klingon'])->assertSessionHasErrors('language');

        // Another language in Make it again needs a new plan, as the plan's words are in the old one.
        $video->update(['status' => VideoStatus::Ready, 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/']]]]);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('<dt>Language</dt><dd>French</dd>', false);
        $again = ['prompt' => 'Post a job', 'target_seconds' => 30, 'devices' => ['desktop'], 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'sample_data' => '0', 'record_as' => '', 'voice' => '', 'new_plan' => '0'];
        $this->post("/video-demo/studio/{$video->id}/again", [...$again, 'language' => 'fr'])->assertRedirect();
        Queue::assertPushed(GenerateVideo::class, fn ($job) => ! $job->replan);
        $video->refresh()->update(['status' => VideoStatus::Ready]);
        $this->post("/video-demo/studio/{$video->id}/again", [...$again, 'language' => 'es'])->assertRedirect();
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->replan);
        $this->assertSame('es', $video->fresh()->language);
    }

    public function test_voice_samples_are_spoken_in_the_voices_language(): void
    {
        $spoken = [];
        $this->app->instance(Speaker::class, new class($spoken) extends Speaker
        {
            public function __construct(public array &$spoken) {}

            public function speak(string $voice, array $lines, bool $mixable = true, string $tone = ''): array
            {
                $this->spoken[] = $lines[0];
                File::put($file = sys_get_temp_dir().'/vd-sample-'.uniqid().'.wav', 'wav');

                return [$lines[0] => ['file' => $file, 'seconds' => 1.0]];
            }
        });
        Voices::flush(['Amélie' => 'Amélie (fr CA)'], ['Amélie' => 'fr-CA']);
        config(['video-demo.voice.providers.openai.key' => 'sk-test']);

        $this->get('/video-demo/studio/voice-sample?voice=system:Am%C3%A9lie&language=de')->assertOk();
        $this->get('/video-demo/studio/voice-sample?voice=openai:marin&language=es')->assertOk();
        $this->get('/video-demo/studio/voice-sample?voice=openai:marin')->assertOk();

        $this->assertSame([SoundController::SAMPLES['fr'], SoundController::SAMPLES['es'], SoundController::SAMPLE], $spoken);
    }

    public function test_the_facade_and_the_command_take_a_language(): void
    {
        Queue::fake();
        $this->installLocalVoice();
        $demo = app(VideoDemo::class);

        $this->assertSame('pt-BR', $demo->make('x', ['language' => 'pt_br'])->language);
        $this->assertNull($demo->make('y')->language);
        $this->artisan('video-demo:generate', ['description' => 'x', '--language' => 'fr', '--queue' => true, '--json' => true])->assertSuccessful();
        $this->assertSame('fr', Video::latest('id')->first()->language);

        try {
            $demo->make('z', ['language' => 'fr', 'voice' => 'kokoro:af_heart']);
            $this->fail('A voice that cannot speak the language was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame("The voice [kokoro:af_heart] doesn't speak French. Choose one that does, or no voice.", $e->getMessage());
        }

        $this->expectExceptionMessage("[Français] isn't a language code.");
        $demo->make('z', ['language' => 'Français']);
    }

    public function test_published_videos_carry_their_subtitles_to_the_player(): void
    {
        Storage::fake('public');
        $video = Video::create(['title' => 'Post a job', 'prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'recordings' => ['desktop' => ['seconds' => 9, 'chapters' => []]], 'version' => 1]);
        $work = Storage::disk('local');
        $work->put($video->workFile('desktop'), 'mp4');
        $work->put($video->workFile('desktop', 'poster'), 'jpg');
        $work->put($video->workFile('desktop', 'subtitles'), "WEBVTT\n");

        app(Publisher::class)->publish($video);
        $entry = app(Manifest::class)->find($video->slug, 'private');

        $this->assertStringEndsWith('-desktop.vtt', $entry['sources']['desktop']['subtitles']);
        $this->assertSame('en', $entry['language']);
        $this->assertContains($entry['subtitles'], Manifest::files($entry));
        Storage::disk('public')->assertExists($entry['subtitles']);
        $this->assertStringContainsString('<track kind="subtitles"', (string) view('video-demo::components.player', ['slug' => $video->slug, 'area' => 'private'])->render());
        $this->assertNull(app(Manifest::class)->url([...$entry, 'subtitles' => null, 'sources' => []], 'subtitles'));
    }

    public function test_cloud_voices_are_offered_when_their_key_is_set(): void
    {
        $this->assertSame([], Voices::options());

        config(['video-demo.voice.providers.openai.key' => 'sk-test', 'video-demo.voice.providers.elevenlabs.key' => 'xi-test']);
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [
            ['voice_id' => 'JBFqnCBsd6RMkjVDRZzb', 'name' => 'George', 'category' => 'premade'],
            ['voice_id' => 'abc123', 'name' => 'My brand voice', 'category' => 'cloned'],
        ], 'has_more' => false])]);

        $options = Voices::options();
        $this->assertSame('Marin, natural', $options['OpenAI voices, about $0.02 a minute']['openai:marin']);
        $this->assertSame(['elevenlabs:JBFqnCBsd6RMkjVDRZzb' => 'George', 'elevenlabs:abc123' => 'My brand voice (yours)'], $options['ElevenLabs voices, about $0.07 a minute']);
        $this->assertSame(0.017, Voices::price('openai:marin'));
        $this->assertSame(0.0, Voices::price('kokoro:af_heart'));
        // Prism's key works too.
        config(['prism.providers.gemini.api_key' => 'g-test']);
        $this->assertSame('g-test', Voices::providerKey('gemini'));
        Http::assertSent(fn ($r) => $r->hasHeader('xi-api-key', 'xi-test') && str_contains($r->url(), 'page_size=100'));
    }

    public function test_cloud_voices_speak_each_line_and_count_what_it_costs(): void
    {
        config([
            'video-demo.voice.providers.openai.key' => 'sk-test',
            'video-demo.voice.providers.gemini.key' => 'g-test',
            'video-demo.voice.providers.elevenlabs.key' => 'xi-test',
        ]);
        $wav = (string) file_get_contents($this->wav($this->root.'/w.wav', 0.5));
        Http::fake([
            'api.openai.com/*' => Http::response($wav),
            'generativelanguage.googleapis.com/*' => Http::response(['steps' => [['content' => [['type' => 'audio', 'data' => base64_encode($wav)]]]]]),
            'api.elevenlabs.io/*' => Http::response('ID3mp3'),
        ]);
        // Clips are converted for mixing with ffmpeg; here they are copied.
        $speaker = new Speaker(new class extends Encoder
        {
            public function toWav(string $input, string $wav): void
            {
                File::copy($input, $wav);
            }
        });

        $clips = $speaker->speak('openai:marin', ['Open Post a job.'], tone: 'calm, unhurried and reassuring');
        $this->assertSame(0.5, $clips['Open Post a job.']['seconds']);
        $this->assertEqualsWithDelta(16 / 1000 * 0.017, $speaker->cost, 0.000001);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/audio/speech' && $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['voice'] === 'marin' && $r['model'] === 'gpt-4o-mini-tts' && $r['response_format'] === 'wav' && str_contains($r['instructions'], 'calm, unhurried'));

        // Saved lines aren't spoken, or paid for, twice.
        $speaker->speak('openai:marin', ['Open Post a job.'], tone: 'calm, unhurried and reassuring');
        $this->assertSame(0.0, $speaker->cost);

        $this->assertSame(0.5, $speaker->speak('gemini:Kore', ['Publish it.'])['Publish it.']['seconds']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1beta/interactions') && $r['model'] === 'gemini-3.8-flash-tts'
            && $r['generation_config']['speech_config'][0]['voice'] === 'Kore' && str_starts_with($r['input'][0]['content'][0]['annotations'][0]['style'], 'warm, clear and friendly, like a person talking'));

        // A sample from ElevenLabs is kept as the MP3 it sends, for the browser to play.
        $sample = $speaker->speak('elevenlabs:JBFqnCBsd6RMkjVDRZzb', ['Hi.'], mixable: false)['Hi.'];
        $this->assertStringEndsWith('.mp3', $sample['file']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb?output_format=mp3_44100_128') && $r['model_id'] === 'eleven_multilingual_v2');

        // Each line hears the ones around it, so the voice-over flows as one.
        $speaker->speak('elevenlabs:JBFqnCBsd6RMkjVDRZzb', ['Your jobs live here.', 'Pick the draft.', 'Done.'], mixable: false);
        Http::assertSent(fn ($r) => ($r['text'] ?? null) === 'Pick the draft.' && $r['previous_text'] === 'Your jobs live here.' && $r['next_text'] === 'Done.');
        Http::assertSent(fn ($r) => ($r['text'] ?? null) === 'Your jobs live here.' && ! isset($r['previous_text']));
        $speaker->speak('openai:marin', ['First.', 'Second.']);
        Http::assertSent(fn ($r) => ($r['input'] ?? null) === 'Second.' && str_contains($r['instructions'], 'follows straight on from: "First."') && str_contains($r['instructions'], 'not an announcer'));
    }

    public function test_a_provider_error_is_explained(): void
    {
        config(['video-demo.voice.providers.openai.key' => 'sk-bad']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided.']], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Openai couldn't speak a line (HTTP 401): Incorrect API key provided.");
        app(Speaker::class)->speak('openai:marin', ['Hello.']);
    }

    public function test_a_voice_that_costs_money_stays_within_the_spending_limit(): void
    {
        config(['video-demo.voice.providers.openai.key' => 'sk-test', 'video-demo.voice.providers.openai.price_per_1k_chars' => 100.0]);
        $fakes = $this->fakeMaking();
        $video = $this->voicedVideo(['voice' => 'openai:marin', 'music' => null, 'max_cost' => 0.5]);

        GenerateVideo::dispatchSync($video, replan: false);
        $video->refresh();

        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame([[]], $fakes['recorder']->voices);
        $this->assertStringContainsString('The voice-over was left out: at about $4.300 it would take the video past its $0.50 limit.', implode("\n", array_column($video->log, 'message')));
    }

    public function test_what_a_cloud_voice_costs_is_added_to_the_video(): void
    {
        config(['video-demo.voice.providers.openai.key' => 'sk-test']);
        // The stand-ins first, then a voice that reports what its new lines cost.
        $this->fakeMaking();
        $this->app->instance(Speaker::class, new class(new Encoder) extends Speaker
        {
            public function speak(string $voice, array $lines, bool $mixable = true, string $tone = ''): array
            {
                $this->cost = 0.0123;

                return collect($lines)->mapWithKeys(fn ($l) => [$l => ['file' => "/c/$l.wav", 'seconds' => 1.0]])->all();
            }
        });
        $video = $this->voicedVideo(['voice' => 'openai:marin', 'music' => null, 'cost' => 0.2]);

        GenerateVideo::dispatchSync($video, replan: false);

        $this->assertEqualsWithDelta(0.2123, $video->fresh()->cost, 0.00001);
        $this->assertContains('The voice-over cost $0.0123', array_column($video->fresh()->log, 'message'));
    }

    public function test_clean_can_remove_the_local_voice(): void
    {
        $this->installLocalVoice();
        File::ensureDirectoryExists($this->root.'/model/onnx');
        File::put($this->root.'/model/onnx/model.onnx', str_repeat('m', 1024));

        $this->artisan('video-demo:clean', ['--voice' => true, '--dry-run' => true])
            ->expectsOutputToContain('the local voice (kokoro-js)')
            ->expectsOutputToContain('the local voice model')
            ->assertSuccessful();

        $this->artisan('video-demo:clean', ['--voice' => true])->assertSuccessful();
        $this->assertFalse(Voices::localInstalled());
        $this->assertDirectoryDoesNotExist($this->root.'/model');
    }

    public function test_a_local_voice_keeps_its_name_where_it_is_not_installed(): void
    {
        config(['video-demo.recorder.node_modules' => '/nowhere']);

        $this->assertSame('Heart, warm (US)', Voices::label('kokoro:af_heart'));
        $this->assertSame('openai:nobody', Voices::label('openai:nobody'));
    }
}
