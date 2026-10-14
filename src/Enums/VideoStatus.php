<?php

namespace Ogunsakin01\VideoDemo\Enums;

enum VideoStatus: string
{
    case Queued = 'queued';
    case Planning = 'planning';
    case Recording = 'recording';
    case Encoding = 'encoding';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Planning => 'Claude is reading the code',
            self::Recording => 'Recording',
            self::Encoding => 'Encoding',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    public function isWorking(): bool
    {
        return in_array($this, [self::Queued, self::Planning, self::Recording, self::Encoding], true);
    }
}
