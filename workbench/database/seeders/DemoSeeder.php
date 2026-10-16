<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Exporter;

/**
 * Sample videos for the demo app. They all play one clip, recorded from the
 * demo app's "Post a job" page in each version by
 * vendor/bin/testbench demo:record-tours sample. The studio and library
 * around them are real.
 */
class DemoSeeder extends Seeder
{
    protected string $assets = __DIR__.'/../../demo-assets';

    /**
     * Seed the sample videos and video requests.
     */
    public function run(Publisher $publisher): void
    {
        Storage::disk('demo-videos')->deleteDirectory('video-demo');
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $styles = VideoStyle::options()->values();
        $plan = json_decode(file_get_contents(__DIR__.'/../../tours/sample.json'), true);

        // Each sample: title, description, library, desktop poster, AI cost, versions, published.
        $samples = [
            ['Post a job', 'How a recruiter posts a new job, from the jobs page to a live listing.', 'public', 'form', 0.1137, ['desktop', 'tv', 'tablet', 'mobile'], true],
            ['Invite a teammate', 'How an admin invites a colleague and chooses what they can see.', 'public', 'caption', 0.0942, ['desktop', 'mobile'], true],
            ['Review applicants', 'How to shortlist applicants for a job and leave notes for the team.', 'public', 'done', 0.1281, ['desktop', 'tablet'], true],
            ['Run monthly payroll', 'How finance staff check and approve the monthly payroll run.', 'private', 'caption', 0.1418, ['desktop'], true],
            ['Change notification emails', 'How to choose which emails you get about new applicants.', 'public', 'done', 0.0825, ['desktop', 'tv'], false],
        ];

        $videos = [];
        foreach ($samples as $i => [$title, $prompt, $visibility, $poster, $cost, $devices, $publish]) {
            $recordings = [];
            foreach ($devices as $device) {
                $recordings[$device] = json_decode(file_get_contents($this->asset($device, 'json')), true);
            }

            $video = Video::create([
                'title' => $title,
                'prompt' => $prompt,
                'style' => $styles[$i % $styles->count()]->snapshot(),
                'devices' => $devices,
                'recordings' => $recordings,
                'target_seconds' => 45,
                'model' => $i === 1 ? 'claude-sonnet-5-5' : 'claude-opus-5-5',
                'effort' => 'high',
                'visibility' => $visibility,
                'status' => VideoStatus::Ready,
                'plan' => [
                    'title' => $plan['title'],
                    'summary' => $prompt,
                    'steps' => $this->steps($plan['steps'], $title),
                    'chapters' => $recordings['desktop']['chapters'],
                ],
                'log' => $this->log($recordings, $cost),
                'input_tokens' => 31,
                'cache_write_tokens' => 21_400,
                'cache_read_tokens' => 164_000,
                'output_tokens' => 7_900,
                'cost' => $cost,
                'estimate_low' => round($cost * 0.55, 4),
                'estimate_high' => round($cost * 1.9, 4),
                'duration_seconds' => $recordings['desktop']['seconds'],
                'version' => 1,
                'created_at' => now()->subDays(5 - $i)->subHours(3),
                'updated_at' => now()->subDays(5 - $i),
            ]);

            foreach ($devices as $device) {
                $work->put($video->workFile($device), file_get_contents($this->asset($device, 'mp4')));
                $work->put($video->workFile($device, 'poster'), file_get_contents(
                    $device === 'desktop' ? "$this->assets/poster-$poster.jpg" : $this->asset($device, 'jpg')
                ));
            }

            if ($publish) {
                $publisher->publish($video);
            }
            $videos[$title] = $video;
        }

        $this->french($videos['Post a job'], $publisher);
        $this->broken($videos['Review applicants']);
        $this->gif($videos['Post a job']);
        $this->stats();

        Video::create([
            'title' => 'Export invoices to CSV',
            'style' => $styles->first()->snapshot(),
            'devices' => ['desktop', 'mobile'],
            'prompt' => 'How an accountant exports this month\'s invoices to a spreadsheet.',
            'target_seconds' => 45, 'model' => 'claude-opus-5-5', 'effort' => 'high',
            'status' => VideoStatus::Failed,
            'error' => 'Step 7 failed (Desktop): Could not find a visible element with role "button", name "Export". The export button only shows for accounts with the Finance role; set VIDEO_DEMO_LOGIN_AS to a finance user.',
            'log' => [
                ['at' => now()->subHours(2)->toIso8601String(), 'message' => 'Claude is reading the code'],
                ['at' => now()->subHours(2)->addSeconds(48)->toIso8601String(), 'message' => 'Plan ready: 14 steps, about 44 seconds'],
                ['at' => now()->subHours(2)->addSeconds(51)->toIso8601String(), 'message' => 'Recording the Desktop version'],
                ['at' => now()->subHours(2)->addSeconds(70)->toIso8601String(), 'message' => 'Step 7 failed (Desktop): Could not find a visible element with role "button", name "Export".'],
                ['at' => now()->subHours(2)->addSeconds(70)->toIso8601String(), 'message' => 'Asking Claude to fix the plan'],
                ['at' => now()->subHours(2)->addSeconds(96)->toIso8601String(), 'message' => 'Step 7 failed (Desktop): Could not find a visible element with role "button", name "Export".'],
            ],
            'cost' => 0.1894, 'estimate_low' => 0.09, 'estimate_high' => 0.41,
            'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);

        // A video stopped by the page check: the app had a bug, so no AI was spent.
        Video::create([
            'title' => 'Send this month\'s payslips',
            'style' => $styles->first()->snapshot(),
            'devices' => ['desktop'],
            'prompt' => 'How a payroll admin checks and sends this month\'s payslips.',
            'start_path' => '/payroll',
            'target_seconds' => 60, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Failed,
            'error' => "A page isn't working, so nothing was recorded: /payroll: The page returned HTTP 500.",
            'issues' => [
                ['path' => '/payroll', 'problem' => 'The page returned HTTP 500', 'status' => 500, 'url' => null, 'detail' => 'Undefined variable $period (resources/views/payroll/index.blade.php:14)'],
            ],
            'log' => [
                ['at' => now()->subMinutes(20)->toIso8601String(), 'message' => 'Queued'],
                ['at' => now()->subMinutes(20)->addSeconds(2)->toIso8601String(), 'message' => 'Checking the start page (1)'],
                ['at' => now()->subMinutes(20)->addSeconds(6)->toIso8601String(), 'message' => "Failed: A page isn't working, so nothing was recorded: /payroll: The page returned HTTP 500."],
            ],
            'cost' => 0, 'estimate_low' => 0.17, 'estimate_high' => 0.52,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20),
        ]);

        foreach (['How do I add a second admin?', 'Where do I change the company logo?', 'Can I export applicants to a spreadsheet?'] as $i => $question) {
            VideoRequest::create(['question' => $question, 'created_at' => now()->subDays($i + 1)]);
        }
    }

    /**
     * Get a sample's steps. "Post a job" opens the form itself, so the help
     * button on that page lists it; "Review applicants" still clicks the
     * button by its old name, so the check finds it broken.
     */
    protected function steps(array $steps, string $title): array
    {
        if ($title === 'Post a job') {
            $steps[1]['url'] = '/jobs/create';
            array_splice($steps, 2, 1);
        }
        if ($title === 'Review applicants') {
            foreach ($steps as $i => $step) {
                if (($step['target']['name'] ?? null) === 'Publish job') {
                    $steps[$i]['target']['name'] = 'Post job';
                }
            }
        }

        return $steps;
    }

    /**
     * A French version of a video, as "Make it in this language" makes it.
     * It plays the same sample clip.
     */
    protected function french(Video $video, Publisher $publisher): void
    {
        $words = [
            'Post a job in a minute' => ['Publier une offre en une minute', 'Du formulaire à l’annonce en ligne.'],
            'Start with the basics' => ['Commencez par l’essentiel', 'Un titre, un contrat et un lieu.'],
            'Live straight away' => ['En ligne tout de suite', 'Les candidats la voient dès maintenant.'],
            'Your job is live' => ['Votre offre est en ligne', 'Il ne reste plus qu’à attendre les candidatures.'],
        ];
        $plan = $video->plan;
        foreach ($plan['steps'] as $i => $step) {
            if (isset($words[$step['title'] ?? ''])) {
                [$plan['steps'][$i]['title'], $plan['steps'][$i]['text']] = $words[$step['title']];
            }
        }
        $chapters = ['Open the form' => 'Ouvrir le formulaire', 'Fill in the details' => 'Remplir les détails', 'Publish' => 'Publier'];
        $translate = fn (array $list) => array_map(fn ($c) => [...$c, 'title' => $chapters[$c['title']] ?? $c['title']], $list);
        $plan['title'] = 'Publier une offre';
        $plan['summary'] = 'Comment un recruteur publie une offre, de la page des offres à l’annonce en ligne.';
        $plan['chapters'] = $translate($plan['chapters']);

        $french = Video::create([
            ...$video->only(['prompt', 'style', 'devices', 'target_seconds', 'model', 'effort', 'visibility', 'duration_seconds', 'version']),
            'slug' => 'post-a-job-fr',
            'title' => 'Publier une offre',
            'language' => 'fr',
            'source_video_id' => $video->id,
            'status' => VideoStatus::Ready,
            'plan' => $plan,
            'recordings' => array_map(fn ($r) => [...$r, 'chapters' => $translate($r['chapters'] ?? [])], $video->recordings),
            'log' => [['at' => now()->subHours(20)->toIso8601String(), 'message' => 'Queued: the French version of "Post a job"'],
                ['at' => now()->subHours(20)->addSeconds(14)->toIso8601String(), 'message' => 'Translated the words. The AI cost $0.0081.'],
                ['at' => now()->subHours(20)->addSeconds(19)->toIso8601String(), 'message' => 'Checked the steps with the app in French'],
                ['at' => now()->subHours(20)->addSeconds(130)->toIso8601String(), 'message' => 'Ready.']],
            'cost' => 0.0081,
            'created_at' => now()->subHours(20), 'updated_at' => now()->subHours(20),
        ]);
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        foreach ($video->devices as $device) {
            foreach (['video', 'poster'] as $kind) {
                $work->put($french->workFile($device, $kind), $work->get($video->workFile($device, $kind)));
            }
        }
        $publisher->publish($french);
    }

    /**
     * Mark a video as broken by a change to the app, as the last check found.
     */
    protected function broken(Video $video): void
    {
        $step = collect($video->plan['steps'])->search(fn ($s) => ($s['target']['name'] ?? null) === 'Post job');
        Storage::disk(config('video-demo.storage.work_disk'))->put($video->workPath('verify.jpg'), file_get_contents("$this->assets/poster-form.jpg"));
        $video->forceFill([
            'verified_at' => now()->subMinutes(12),
            'verify_result' => [
                'status' => 'done', 'ok' => false, 'checked' => ['desktop'], 'device' => 'desktop', 'step' => $step + 1, 'action' => 'click',
                'error' => 'Could not find a visible element with role "button", name "Post job".',
                'url' => url('/jobs/create'), 'issues' => [], 'screenshot' => true,
            ],
        ])->save();
    }

    /**
     * A GIF of a video, as "Make it" under Downloads makes it.
     */
    protected function gif(Video $video): void
    {
        $gif = file_get_contents(__DIR__.'/../../../docs/library-tour-mobile.gif');
        Storage::disk(config('video-demo.storage.work_disk'))->put(Exporter::file($video, 'gif'), $gif);
        $video->forceFill(['exports' => ['gif' => ['status' => 'ready', 'version' => $video->version, 'bytes' => strlen($gif), 'at' => now()->subHours(3)->toIso8601String()]]])->save();
    }

    /**
     * Thirty days of plays, and the searches that found nothing.
     */
    protected function stats(): void
    {
        $rows = [];
        // Plays, and how many reached each quarter, out of 100.
        $shapes = ['post-a-job' => [212, [94, 81, 74, 69]], 'post-a-job-fr' => [38, [92, 85, 80, 77]], 'invite-a-teammate' => [96, [88, 52, 47, 41]], 'review-applicants' => [61, [90, 79, 71, 66]]];
        foreach ($shapes as $slug => [$plays, $quarters]) {
            foreach (range(0, 29) as $day) {
                $n = max(1, (int) round($plays / 30 * (0.6 + 0.8 * (($day * 7 + strlen($slug)) % 10) / 10)));
                foreach (['play' => 100, '25' => $quarters[0], '50' => $quarters[1], '75' => $quarters[2], '100' => $quarters[3]] as $event => $share) {
                    $rows[] = ['slug' => $slug, 'day' => now()->subDays($day)->toDateString(), 'event' => $event, 'term' => '', 'hits' => (int) round($n * $share / 100)];
                }
            }
        }
        foreach (['export invoices' => 14, 'company logo' => 9, 'two factor' => 6, 'delete account' => 3] as $term => $hits) {
            $rows[] = ['slug' => '', 'day' => now()->subDays($hits)->toDateString(), 'event' => 'search', 'term' => $term, 'hits' => $hits];
        }
        DB::table('video_demo_stats')->insert(array_values(array_filter($rows, fn ($r) => $r['hits'] > 0)));
    }

    /**
     * Get the path of the sample clip or poster for a version.
     */
    protected function asset(string $device, string $extension): string
    {
        return "$this->assets/sample".($device === 'desktop' ? '' : "-$device").".$extension";
    }

    /**
     * Build a sample generation log for the given recordings.
     */
    protected function log(array $recordings, float $cost): array
    {
        $start = now()->subDays(1);
        $entries = [
            [0, 'Queued'], [1, 'Claude is reading the code'], [6, 'Read routes/web.php'],
            [11, 'Read app/Http/Controllers/JobController.php'], [17, 'Read resources/views/jobs/create.blade.php'],
            [22, 'Searched for "Publish job"'], [39, 'Plan ready: 13 steps, about 40 seconds'],
        ];
        $t = 40;
        foreach ($recordings as $device => $recording) {
            $label = config("video-demo.devices.$device.label");
            $entries[] = [$t, "Recording the $label version"];
            $t += (int) ceil($recording['seconds']) + 4;
            $entries[] = [$t, sprintf('Recorded %.1f seconds (%s)', $recording['seconds'], $label)];
        }
        $entries[] = [$t, count($recordings) > 1 ? 'Encoding '.count($recordings).' versions' : 'Encoding the video'];
        $entries[] = [$t + 9 * count($recordings), sprintf('Ready. Claude cost $%.4f in total.', $cost)];

        return array_map(fn ($e) => ['at' => $start->copy()->addSeconds($e[0])->toIso8601String(), 'message' => $e[1]], $entries);
    }
}
