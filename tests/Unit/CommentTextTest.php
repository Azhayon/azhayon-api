<?php

namespace Tests\Unit;

use App\Support\Api\CommentText;
use PHPUnit\Framework\TestCase;

class CommentTextTest extends TestCase
{
    public function test_it_cleans_whitespace_and_hidden_characters(): void
    {
        $this->assertSame("Hello\n\nworld", CommentText::clean("  Hello\r\n\r\n\r\n\r\nworld \x07\u{200B}  "));
        $this->assertSame("a\nb", CommentText::clean("a  \nb"));
    }

    public function test_it_keeps_ordinary_text_untouched(): void
    {
        $this->assertSame("a\tb <3 &amp; ok", CommentText::clean("a\tb <3 &amp; ok"));
    }

    public function test_invalid_utf8_becomes_empty_and_links_are_counted(): void
    {
        $this->assertSame('', CommentText::clean("\xC3\x28"));
        $this->assertSame(3, CommentText::linkCount('see https://a.com, www.b.com and HTTP://c.com'));
        $this->assertSame(0, CommentText::linkCount('no links <3'));
    }
}