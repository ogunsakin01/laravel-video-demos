<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A way of making videos: how Claude writes them and how cards and captions
 * look. The defaults come from config('video-demo.styles'); developers add
 * their own on the studio's Styles page.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $direction
 * @property string $theme
 * @property string $accent
 * @property string $captions
 * @property string $pace
 * @property int $position
 */
class VideoStyle extends Model
{
    public const THEMES = ['light' => 'Light', 'dark' => 'Dark'];

    public const CAPTIONS = ['bottom' => 'Bottom', 'top' => 'Top'];

    public const PACES = ['relaxed' => 'Relaxed', 'normal' => 'Normal', 'brisk' => 'Brisk'];

    protected $table = 'video_demo_styles';

    protected $guarded = ['id'];

    /** All styles, copying the defaults from config the first time */
    public static function options(): Collection
    {
        if (! static::query()->exists()) {
            static::seedFromConfig();
        }

        return static::query()->orderBy('position')->orderBy('id')->get();
    }

    public static function seedFromConfig(): void
    {
        $position = 0;
        foreach (config('video-demo.styles', []) as $key => $style) {
            static::firstOrCreate(['key' => $key], [...static::defaults(), ...$style, 'position' => $position++]);
        }
    }

    public static function defaults(): array
    {
        return ['theme' => 'light', 'accent' => '#171717', 'captions' => 'bottom', 'pace' => 'normal'];
    }

    protected static function booted(): void
    {
        static::creating(function (VideoStyle $style) {
            $style->key ??= Str::slug($style->name).'-'.Str::lower(Str::random(4));
            $style->position ??= (int) static::max('position') + 1;
        });
    }

    /** What a video keeps, so recording it again later looks the same */
    public function snapshot(): array
    {
        return $this->only(['key', 'name', 'direction', 'theme', 'accent', 'captions', 'pace']);
    }
}
