<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

use Ogunsakin01\VideoDemo\Ai\Redactor;

/**
 * Hides secrets in everything a driver sends: user messages, tool results
 * and one-off prompts. Callers may redact too; redacted text stays as it is.
 * It runs as each message is appended, so the conversation is never edited.
 */
trait RedactsSecrets
{
    protected ?Redactor $redactor = null;

    protected function redact(string $text): string
    {
        return ($this->redactor ??= new Redactor)->redact($text);
    }
}
