<?php

namespace Ogunsakin01\VideoDemo\Enums;

/**
 * The stages a video moves through, from queued to ready, failed or stopped.
 */
enum VideoStatus: string
{
    case Queued = 'queued';
    case Checking = 'checking';
    case Planning = 'planning';
    case Recording = 'recording';
    case Encoding = 'encoding';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stopped = 'stopped';

    /**
     * Get the label shown in the studio.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Checking => 'Checking the pages',
            self::Planning => 'The AI is reading the code',
            self::Recording => 'Recording',
            self::Encoding => 'Encoding',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Stopped => 'Stopped',
        };
    }

    /**
     * Determine if the video is still being made.
     */
    public function isWorking(): bool
    {
        return in_array($this, [self::Queued, self::Checking, self::Planning, self::Recording, self::Encoding], true);
    }
}
