<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

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
                    'steps' => $plan['steps'],
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
        }

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

        foreach (['How do I add a second admin?', 'Where do I change the company logo?', 'Can I export applicants to a spreadsheet?'] as $i => $question) {
            VideoRequest::create(['question' => $question, 'created_at' => now()->subDays($i + 1)]);
        }
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
