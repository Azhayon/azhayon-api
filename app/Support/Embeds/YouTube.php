<?php

namespace App\Support\Embeds;

class YouTube
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /** Returns the 11-character video ID from a YouTube link (or a bare ID), or null. */
    public static function extractId(string $input): ?string
    {
        $input = trim($input);

        if (preg_match(self::ID_PATTERN, $input)) {
            return $input;
        }

        $parts = parse_url($input);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) preg_replace('/^www\./i', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        $id = null;

        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0];
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $matches)) {
                $id = $matches[1];
            }
        }

        return is_string($id) && preg_match(self::ID_PATTERN, $id) ? $id : null;
    }
}