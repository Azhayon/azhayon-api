<?php

namespace App\Support\Api;

use Closure;

/** Turns what a visitor typed into safe pieces for LIKE and full-text queries. */
final class SearchQuery
{
    /** Words MySQL's InnoDB full-text engine ignores (3+ letters; shorter ones are dropped anyway). */
    private const STOPWORDS = [
        'about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this',
        'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    private const MAX_FULLTEXT_TERMS = 6;

    /** @param  list<string>  $tokens */
    private function __construct(
        public readonly string $text,
        public readonly string $like,
        public readonly array $tokens,
    ) {}

    public static function parse(string $raw): ?self
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);

        if ($clean === null) {
            return null; // not valid UTF-8
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $clean));

        if (mb_strlen($text) < 2) {
            return null;
        }

        // Letters, numbers and the marks that belong to them (Bengali vowel signs, for example).
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*/u', mb_strtolower($text), $matches);

        if ($matches[0] === []) {
            return null;
        }

        return new self($text, addcslashes($text, '\\%_'), $matches[0]);
    }

    /** A validation rule: at least 2 characters, including a letter or number. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || self::parse($value) === null) {
                $fail('Enter at least 2 characters, including a letter or number.');
            }
        };
    }

    /** "+word* +other*": every word must match, as a prefix. Null when no word can be full-text searched. */
    public function boolean(): ?string
    {
        $terms = $this->terms();

        return $terms === [] ? null : implode(' ', array_map(fn (string $term): string => "+{$term}*", $terms));
    }

    public function natural(): ?string
    {
        $terms = $this->terms();

        return $terms === [] ? null : implode(' ', $terms);
    }

    /** @return list<string> */
    private function terms(): array
    {
        $terms = array_values(array_filter(
            $this->tokens,
            fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, self::STOPWORDS, true),
        ));

        return array_slice($terms, 0, self::MAX_FULLTEXT_TERMS);
    }
}