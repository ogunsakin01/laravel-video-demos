<?php

namespace Ogunsakin01\VideoDemo\Enums;

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

    public function isWorking(): bool
    {
        return in_array($this, [self::Queued, self::Checking, self::Planning, self::Recording, self::Encoding], true);
    }
}
