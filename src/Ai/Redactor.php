<?php

namespace Ogunsakin01\VideoDemo\Ai;

/**
 * Hides secrets in code, search results and page snapshots before they are sent to the model.
 * The deny list in video-demo.ai.code.deny keeps files like .env out; this catches keys in code or on a page.
 */
class Redactor
{
    /**
     * The secret patterns, by label. The whole match is hidden, or the "value" group when there is one.
     */
    protected const PATTERNS = [
        'private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        'Anthropic API key' => '/\bsk-ant-[A-Za-z0-9_\-]{20,}/',
        'OpenAI API key' => '/\bsk-(?:proj-|svcacct-)?[A-Za-z0-9_\-]{32,}/',
        'Stripe secret key' => '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}/',
        'Stripe webhook secret' => '/\bwhsec_[A-Za-z0-9]{16,}/',
        'AWS access key' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'GitHub token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})/',
        'Slack token' => '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/',
        'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'JSON web token' => '/\beyJ[A-Za-z0-9_\-]{10,}\.eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/',
        // A long, key-like quoted value assigned to something named like a secret. The value needs
        // letters and digits and no | : or spaces, so rules such as 'password' => 'required|min:8' stay readable.
        'secret value' => '/(?:pass(?:word|wd)?|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret)[\'"]?\s*(?:=>|=|:)\s*[\'"](?<value>(?=[^\'"\s]*\d)(?=[^\'"\s]*[A-Za-z])[A-Za-z0-9_\-+\/=.]{12,})[\'"]/i',
    ];

    protected int $hidden = 0;

    /**
     * Replace the secrets in the text with "[redacted: kind]".
     */
    public function redact(string $text): string
    {
        if (! config('video-demo.ai.code.redact', true)) {
            return $text;
        }

        $patterns = [...self::PATTERNS, ...config('video-demo.ai.code.redact_patterns', [])];

        foreach ($patterns as $label => $pattern) {
            $text = preg_replace_callback($pattern, function (array $m) use ($label) {
                $this->hidden++;
                $mark = '[redacted: '.(is_string($label) ? $label : 'secret').']';

                return isset($m['value']) && $m['value'] !== ''
                    ? str_replace($m['value'], $mark, $m[0])
                    : $mark;
            }, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Get how many secrets were hidden since the last call, and reset the count.
     */
    public function takeCount(): int
    {
        [$count, $this->hidden] = [$this->hidden, 0];

        return $count;
    }
}
