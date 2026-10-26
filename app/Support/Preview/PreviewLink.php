<?php

namespace App\Support\Preview;

use App\Models\Post;
use Illuminate\Support\Facades\URL;

final class PreviewLink
{
    public const LIFETIME_MINUTES = 60;

    /**
     * The address of the frontend's preview page for a post, valid for one hour.
     *
     * The signature covers only the API path and its query (a "relative" signature), so it
     * stays valid whatever host or proxy the request arrives through.
     */
    public static function for(Post $post): string
    {
        $relative = URL::temporarySignedRoute(
            'preview.post',
            now()->addMinutes(self::LIFETIME_MINUTES),
            ['id' => $post->getKey()],
            absolute: false,
        );

        return rtrim((string) config('azhayon.frontend_url'), '/')
            .'/preview/'.$post->getKey()
            .'?'.parse_url($relative, PHP_URL_QUERY);
    }
}