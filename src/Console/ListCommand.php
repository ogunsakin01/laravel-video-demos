<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Models\Video;

/** The studio's videos, for people and for AI agents (--json) */
class ListCommand extends Command
{
    protected $signature = 'video-demo:list {--json : Print the videos as JSON}';

    protected $description = 'List the videos made in the studio';

    public function handle(): int
    {
        $videos = Video::latest()->get()->map(fn (Video $v) => [
            'id' => $v->id,
            'slug' => $v->slug,
            'title' => $v->title ?: $v->prompt,
            'status' => $v->status->value,
            'versions' => $v->recordedDevices() ?: $v->deviceList(),
            'seconds' => $v->duration_seconds,
            'cost' => round($v->cost, 4),
            'visibility' => $v->visibility,
            'published' => $v->isPublished(),
            'error' => $v->error,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($videos->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($videos->isEmpty()) {
            $this->components->info('No videos yet. Make one with: php artisan video-demo:generate "how to …"');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Slug', 'Status', 'Versions', 'Length', 'Cost', 'Library'],
            $videos->map(fn ($v) => [
                $v['id'], $v['slug'], $v['status'], implode(', ', $v['versions']),
                $v['seconds'] ? round($v['seconds']).'s' : '—', '$'.number_format($v['cost'], 2),
                $v['published'] ? $v['visibility'] : '—',
            ]),
        );

        return self::SUCCESS;
    }
}
