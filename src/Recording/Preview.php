<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Throwable;

/**
 * Records a video's saved plan again into a folder, as an MP4 with a poster
 * and a GIF, without changing the video or publishing anything: a preview of
 * how it looks with the app as it is now, such as on a pull request. It costs
 * no AI, and is silent, as a preview is about what the screen shows.
 */
class Preview
{
    /**
     * Create a new preview instance.
     */
    public function __construct(protected Recorder $recorder, protected Encoder $encoder) {}

    /**
     * Record the video's plan into $dir, named after its slug.
     *
     * @return array{ok: bool, mp4: ?string, poster: ?string, gif: ?string, seconds: ?float, step: ?int, error: ?string}
     */
    public function make(Video $video, string $dir, bool $gif = true): array
    {
        $result = ['ok' => false, 'mp4' => null, 'poster' => null, 'gif' => null, 'seconds' => null, 'step' => null, 'error' => null];
        $device = $video->primaryDevice();
        $work = "$dir/.work-{$video->slug}";
        File::ensureDirectoryExists($dir);
        $sandbox = null;

        try {
            if ($video->sample_data) {
                $sandbox = Sandbox::prepareFor($video, true, fn () => null);
                $this->recorder->sandbox = $sandbox;
            }
            $this->recorder->locale = $video->recordingLocale();

            $plan = Plan::fromArray($video->plan ?? [])->withoutVoice();
            $run = $this->recorder->record($plan, $work, $video->style ?? [], $device, $video->record_as, null, $video->playbackSpeed());
            if (! $run->ok) {
                return [...$result, 'step' => $run->failedStep, 'error' => $run->error ?: 'The recording failed.'];
            }

            $mp4 = "$dir/{$video->slug}.mp4";
            $poster = "$dir/{$video->slug}.jpg";
            $this->encoder->encode((string) $run->video, $mp4, $poster, $run->trimSeconds, $video->playbackSpeed(), $run->timeline);
            $result = [...$result, 'ok' => true, 'mp4' => $mp4, 'poster' => $poster, 'seconds' => $run->seconds];

            if ($gif) {
                // Long videos get fewer frames, to keep the file small enough to share.
                $fps = $run->seconds > 45 ? 8 : 12;
                $this->encoder->frames($mp4, "$work/frames", $fps);
                // Made in the work folder, which its notes stay in, then moved.
                $this->recorder->makeGif("$work/frames", "$work/gif/video.gif", $fps, $run->seconds > 45 ? 640 : 800);
                File::move("$work/gif/video.gif", $result['gif'] = "$dir/{$video->slug}.gif");
            }

            return $result;
        } catch (Throwable $e) {
            return [...$result, 'error' => $e->getMessage()];
        } finally {
            $sandbox?->delete();
            $this->recorder->sandbox = null;
            $this->recorder->locale = null;
            File::deleteDirectory($work);
        }
    }
}
