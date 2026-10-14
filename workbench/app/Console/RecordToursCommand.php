<?php

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Recording\Camera;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * Records the package's own videos with its recorder, from the plans in
 * workbench/tours. The plans are hand-written in the format the AI model
 * writes, so this costs nothing. Each plan file may also set:
 *   "devices": versions to record (default ["desktop"])
 *   "out": folder to write to (default "docs"), as <name>[-<device>].mp4 and .jpg
 *   "site": "app" for the demo app (default), "fixture" for tests/fixtures/app
 *   "style": the look of cards and captions, as on the studio's Styles page
 *   "style_key": a built-in style from config('video-demo.styles') instead, such as "spotlight"
 *   "screens": extra screen sizes for this tour, such as a square one for social posts
 *
 *   vendor/bin/testbench workbench:build
 *   vendor/bin/testbench serve --port=8123
 *   vendor/bin/testbench demo:record-tours --url=http://127.0.0.1:8123
 */
class RecordToursCommand extends Command
{
    protected $signature = 'demo:record-tours {tour?* : Only these tours} {--url=http://127.0.0.1:8123 : Where the demo app is served}';

    protected $description = 'Record the package tour videos and sample clips';

    /**
     * Execute the console command.
     */
    public function handle(Recorder $recorder, Encoder $encoder): int
    {
        $root = realpath(__DIR__.'/../../..');
        config(['video-demo.recorder.login_as' => null]);

        $tours = $this->argument('tour') ?: array_map(fn ($f) => basename($f, '.json'), File::glob("$root/workbench/tours/*.json"));

        foreach ($tours as $tour) {
            $data = json_decode(File::get("$root/workbench/tours/$tour.json"), true);
            $plan = Plan::fromArray($data);
            $style = isset($data['style_key']) ? config('video-demo.styles.'.$data['style_key']) : ($data['style'] ?? []);
            foreach ($data['screens'] ?? [] as $key => $screen) {
                config(["video-demo.devices.$key" => [...['touch' => false], ...$screen]]);
            }
            $devices = $data['devices'] ?? ['desktop'];
            $out = "$root/".($data['out'] ?? 'docs');
            config(['video-demo.recorder.base_url' => ($data['site'] ?? 'app') === 'fixture'
                ? 'file://'.realpath("$root/tests/fixtures/app")
                : $this->option('url')]);

            foreach ($devices as $device) {
                $name = $tour.($device === 'desktop' ? '' : "-$device");
                $work = storage_path("video-demo/tours/$name");

                $result = $recorder->record($plan, $work, $style, $device);
                if (! $result->ok) {
                    $this->components->error("$name: {$result->summary()}");

                    return self::FAILURE;
                }

                File::ensureDirectoryExists($out);
                $encoder->encode($result->video, "$out/$name.mp4", "$out/$name.jpg", $result->trimSeconds, Recorder::speed());
                if (! empty($style['zoom'])) {
                    $screen = Devices::get($device);
                    $keys = Camera::keyframes($result->focus, $screen['width'], $screen['height'], $screen['touch'] ? 1.5 : 1.8);
                    if ($recorder->applyCamera("$out/$name.mp4", $keys, $screen['width'], $screen['height'], $encoder)) {
                        $encoder->poster("$out/$name.mp4", "$out/$name.jpg");
                    } else {
                        $this->components->warn("$name: the zoom could not be added.");
                    }
                }
                if (! isset($data['out'])) {
                    $this->preview("$out/$name.mp4", "$out/$name.gif", $device === 'mobile' ? 260 : 720);
                }
                if (isset($data['out'])) {
                    // Sample clips keep their length and chapters for the demo seeder.
                    File::put("$out/$name.json", json_encode(['seconds' => $result->seconds, 'chapters' => $result->chapters]));
                }
                File::deleteDirectory($work);
                $this->components->info(sprintf('%s (%s): %s/%s.mp4, %.1f seconds', $tour, Devices::get($device)['label'], basename($out), $name, $result->seconds));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Make an animated GIF preview for the README, at the video's own speed.
     * GitHub doesn't play videos stored in the repository, but it does play GIFs.
     */
    protected function preview(string $mp4, string $gif, int $width): void
    {
        Process::timeout(300)->run([
            config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-i', $mp4,
            '-filter_complex', "[0:v]scale=$width:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=none:diff_mode=rectangle",
            '-r', '8', $gif,
        ])->throw();
    }
}
