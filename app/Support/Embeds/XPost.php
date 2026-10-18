<?php

namespace App\Support\Embeds;

class XPost
{
    /**
     * Parses a link to a post on X (or its old twitter.com address).
     *
     * @return array{handle: string, id: string, url: string}|null
     */
    public static function parse(string $url): ?array
    {
        $pattern = '#^https?://(?:www\.|mobile\.)?(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})/status/(\d{1,25})(?:[/?\#].*)?$#i';

        if (! preg_match($pattern, trim($url), $matches)) {
            return null;
        }

        return [
            'handle' => $matches[1],
            'id' => $matches[2],
            'url' => "https://x.com/{$matches[1]}/status/{$matches[2]}",
        ];
    }
}