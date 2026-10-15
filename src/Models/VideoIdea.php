<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video the AI model suggested making, kept until replaced or deleted.
 *
 * @property int $id
 * @property string $title
 * @property string $prompt
 * @property ?string $why
 * @property ?string $start_path
 * @property int $target_seconds
 * @property ?string $style
 * @property ?string $record_as
 * @property ?string $model
 * @property float $cost
 * @property ?int $video_id
 */
class VideoIdea extends Model
{
    protected $table = 'video_demo_ideas';

    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['cost' => 'float'];
    }

    /**
     * Get the video made from this idea.
     *
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
