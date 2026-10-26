<?php

namespace Ogunsakin01\VideoDemo\Library;

/**
 * The scripts the players run in the viewer's browser, from resources/embed:
 * the ones that pause a video while the viewer is away and remember where
 * they got to, which the Blade player puts in the page, and embed.js, which
 * defines <video-demo-player> for any frontend and has the same ones built in.
 */
class PlayerScripts
{
    /**
     * Get the script that pauses a video while the viewer is away.
     */
    public static function attention(): string
    {
        return static::read('attention.js');
    }

    /**
     * Get the script that remembers where a viewer got to in a video.
     */
    public static function resume(): string
    {
        return static::read('resume.js');
    }

    /**
     * Get embed.js: <video-demo-player> and <video-demo-help>.
     */
    public static function embed(): string
    {
        return static::attention()."\n".static::resume()."\n".static::read('video-demo-player.js');
    }

    /**
     * Get the contents of a script in resources/embed.
     */
    protected static function read(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/embed/'.$name);
    }
}
