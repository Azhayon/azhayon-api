<?php

namespace Tests\Unit;

use App\Support\Embeds\XPost;
use App\Support\Embeds\YouTube;
use PHPUnit\Framework\TestCase;

class EmbedParsersTest extends TestCase
{
    public function test_youtube_ids_are_extracted_from_common_links(): void
    {
        $links = [
            'dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            ' https://www.youtube.com/watch?v=dQw4w9WgXcQ ',
        ];

        foreach ($links as $link) {
            $this->assertSame('dQw4w9WgXcQ', YouTube::extractId($link), $link);
        }
    }

    public function test_youtube_rejects_anything_else(): void
    {
        $bad = [
            '',
            'dQw4w9WgXc',
            'dQw4w9WgXcQQ',
            'javascript:alert(1)',
            'https://example.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=<script>',
            'https://www.youtube.com/watch',
        ];

        foreach ($bad as $link) {
            $this->assertNull(YouTube::extractId($link), $link);
        }
    }

    public function test_x_posts_are_parsed_and_normalised(): void
    {
        $expected = ['handle' => 'jack', 'id' => '20', 'url' => 'https://x.com/jack/status/20'];

        foreach ([
            'https://x.com/jack/status/20',
            'https://twitter.com/jack/status/20?s=20',
            'http://www.x.com/jack/status/20/',
        ] as $link) {
            $this->assertSame($expected, XPost::parse($link), $link);
        }
    }

    public function test_x_rejects_anything_else(): void
    {
        foreach ([
            '',
            'https://x.com/jack',
            'https://evil.com/x.com/jack/status/20',
            'https://x.com.evil.com/jack/status/20',
            'javascript:alert(1)',
        ] as $link) {
            $this->assertNull(XPost::parse($link), $link);
        }
    }
}