<?php

namespace App\Support\Http;

/** A cheap user-agent check so crawlers and test tools do not inflate view counts. */
final class BotDetector
{
    private const PATTERN = '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|headlesschrome|lighthouse|pagespeed|curl|wget|python-requests|httpclient|axios|node-fetch|go-http-client|libwww|scrapy/i';

    public static function isBot(?string $userAgent): bool
    {
        $userAgent = trim((string) $userAgent);

        return $userAgent === '' || preg_match(self::PATTERN, $userAgent) === 1;
    }
}