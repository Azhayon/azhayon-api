<?php

namespace App\Support\Api;

/** Cleans what visitors type before it is stored. The result is plain text, never HTML. */
final class CommentText
{
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Control characters (new lines and tabs stay), zero-width characters and text-direction overrides.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}\x{2060}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);

        if ($text === null) {
            return ''; // not valid UTF-8
        }

        $text = preg_replace(['/[ \t]+\n/', "/\n{3,}/"], ["\n", "\n\n"], $text) ?? '';

        return trim($text);
    }

    public static function linkCount(string $text): int
    {
        return (int) preg_match_all('~https?://|www\.~i', $text);
    }
}