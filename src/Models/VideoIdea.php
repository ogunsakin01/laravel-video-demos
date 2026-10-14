<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video the AI suggested making, kept until replaced or deleted
 *
 * @property int $id
 * @property string $title
 * @property string $prompt
 * @property ?string $why
 * @property ?string $start_path
 * @property int $target_seconds
 * @property ?string $style
 * @property ?string $model
 * @property float $cost
 * @property ?int $video_id
 */
class VideoIdea extends Model
{
    protected $table = 'video_demo_ideas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost' => 'float'];
    }

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
