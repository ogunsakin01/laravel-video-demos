<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A question a library visitor asked that no video answered yet.
 *
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
