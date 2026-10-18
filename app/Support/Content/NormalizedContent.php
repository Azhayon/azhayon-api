<?php

namespace App\Support\Content;

final class NormalizedContent
{
    /** @param  array<string, mixed>  $document */
    public function __construct(
        public readonly array $document,
        public readonly int $wordCount,
        public readonly bool $isEmpty,
    ) {}

    public function readingTimeMinutes(): int
    {
        return max(1, (int) ceil($this->wordCount / 200));
    }
}