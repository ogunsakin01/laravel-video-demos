<?php

namespace Ogunsakin01\VideoDemo\Voice;

use RuntimeException;

/**
 * Reads the length of a WAV file from its header, walking past chunks such
 * as LIST that some tools add before the sound data.
 */
class Wav
{
    /**
     * Get how long a WAV file plays, in seconds.
     *
     * @throws RuntimeException When the file is not a WAV file.
     */
    public static function seconds(string $file): float
    {
        $handle = @fopen($file, 'rb');
        if (! $handle) {
            throw new RuntimeException("Can't read $file.");
        }

        try {
            $head = (string) fread($handle, 12);
            if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
                throw new RuntimeException(basename($file).' is not a WAV file.');
            }

            $byteRate = 0;
            while (! feof($handle)) {
                $chunk = fread($handle, 8);
                if (strlen((string) $chunk) < 8) {
                    break;
                }
                ['size' => $size] = unpack('Vsize', substr($chunk, 4, 4));
                $id = substr($chunk, 0, 4);

                if ($id === 'fmt ') {
                    $fmt = (string) fread($handle, $size);
                    $byteRate = unpack('Vrate', substr($fmt, 8, 4))['rate'];

                    continue;
                }
                if ($id === 'data') {
                    if ($byteRate <= 0) {
                        break;
                    }
                    // Tools that stream WAV (ffmpeg to a pipe) may leave the size open.
                    $size = $size === 0xFFFFFFFF || $size === 0 ? filesize($file) - ftell($handle) : $size;

                    return round($size / $byteRate, 3);
                }
                fseek($handle, $size + ($size % 2), SEEK_CUR);
            }
        } finally {
            fclose($handle);
        }

        throw new RuntimeException(basename($file).' has no sound in it.');
    }
}
