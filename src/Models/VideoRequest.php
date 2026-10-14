<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $question
 * @property ?string $page_url
 * @property ?string $user_id
 * @property string $status
 */
class VideoRequest extends Model
{
    protected $table = 'video_demo_requests';

    protected $guarded = ['id'];
}
