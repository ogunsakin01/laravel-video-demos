<?php

namespace Ogunsakin01\VideoDemo\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Make, publish and look up videos from your own code.
 *
 * @method static void signInUsing(\Closure $callback)
 * @method static \Ogunsakin01\VideoDemo\Models\Video make(string $description, array $options = [])
 * @method static \Ogunsakin01\VideoDemo\Models\Video create(string $description, array $options = [], string $log = 'Queued')
 * @method static array estimate(?string $model = null, ?string $effort = null, int $seconds = 60)
 * @method static \Ogunsakin01\VideoDemo\Models\Video stop(\Ogunsakin01\VideoDemo\Models\Video $video)
 * @method static \Ogunsakin01\VideoDemo\Models\Video retry(\Ogunsakin01\VideoDemo\Models\Video $video, bool $newPlan = true, ?bool $sampleData = null)
 * @method static \Ogunsakin01\VideoDemo\Models\Video rerecord(\Ogunsakin01\VideoDemo\Models\Video $video, ?array $devices = null, ?float $speed = null)
 * @method static \Ogunsakin01\VideoDemo\Models\Video translate(\Ogunsakin01\VideoDemo\Models\Video $video, string $language, array $options = [])
 * @method static \Ogunsakin01\VideoDemo\Models\Video updatePlan(\Ogunsakin01\VideoDemo\Models\Video $video, array $plan)
 * @method static string export(\Ogunsakin01\VideoDemo\Models\Video $video, string $format, ?string $device = null)
 * @method static array verify(\Ogunsakin01\VideoDemo\Models\Video $video)
 * @method static \Ogunsakin01\VideoDemo\Models\Video publish(\Ogunsakin01\VideoDemo\Models\Video $video)
 * @method static \Ogunsakin01\VideoDemo\Models\Video unpublish(\Ogunsakin01\VideoDemo\Models\Video $video)
 * @method static void delete(\Ogunsakin01\VideoDemo\Models\Video $video)
 * @method static array embeds(\Ogunsakin01\VideoDemo\Models\Video $video)
 * @method static \Illuminate\Database\Eloquent\Collection videos()
 * @method static \Ogunsakin01\VideoDemo\Models\Video|null find(int|string $idOrSlug)
 * @method static array library(string $area = 'public')
 * @method static array search(string $query, string $area = 'public')
 * @method static array forPage(?string $route = null, string $area = 'public')
 * @method static string|null url(array $entry, string $kind = 'video', ?string $device = null)
 * @method static array suggest(?string $model = null, bool $replace = false)
 * @method static \Illuminate\Database\Eloquent\Collection ideas()
 * @method static \Illuminate\Database\Eloquent\Collection styles()
 * @method static array userTypes()
 * @method static array models()
 * @method static array voices()
 * @method static array music()
 * @method static array pages(?string $as = null, bool $fresh = false)
 * @method static array checkPages(array $paths, ?string $as = null)
 *
 * @see \Ogunsakin01\VideoDemo\VideoDemo
 */
class VideoDemo extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Ogunsakin01\VideoDemo\VideoDemo::class;
    }
}
