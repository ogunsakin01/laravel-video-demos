<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One AI cost, recorded as it happens: planning a video, improving or
 * narrating it, its voice-over, Complete with AI or suggesting ideas. The
 * total spent is their sum, and it stays when a video is deleted.
 *
 * @property int $id
 * @property ?int $video_id
 * @property string $kind
 * @property ?string $model
 * @property float $cost
 * @property ?Carbon $created_at
 */
class Spend extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'video_demo_spending';

    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['cost' => 'float'];
    }

    /**
     * Record a cost, when there is one.
     */
    public static function record(string $kind, float $cost, ?int $videoId = null, ?string $model = null): void
    {
        if ($cost > 0) {
            static::create(['kind' => $kind, 'cost' => round($cost, 6), 'video_id' => $videoId, 'model' => $model]);
        }
    }

    /**
     * Get everything spent on AI so far, in US dollars.
     */
    public static function total(): float
    {
        return round((float) static::query()->sum('cost'), 6);
    }
}
