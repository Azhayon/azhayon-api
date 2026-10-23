<?php

namespace Tests\Unit;

use App\Support\Api\SearchQuery;
use PHPUnit\Framework\TestCase;

class SearchQueryTest extends TestCase
{
    public function test_text_is_cleaned_and_split_into_lowercase_words(): void
    {
        $search = SearchQuery::parse("  Jujutsu \t  Kaisen\n ");

        $this->assertSame('Jujutsu Kaisen', $search->text);
        $this->assertSame(['jujutsu', 'kaisen'], $search->tokens);
    }

    public function test_unusable_input_is_rejected(): void
    {
        foreach (['', ' ', 'a', ' a ', '%%', '--', '+-', '!?', "\x00\x01\x02", "\xC3\x28"] as $raw) {
            $this->assertNull(SearchQuery::parse($raw), bin2hex($raw));
        }
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $this->assertSame('50\%\_off', SearchQuery::parse('50%_off')->like);
        $this->assertSame('back\\\\slash', SearchQuery::parse('back\slash')->like);
        $this->assertSame('Plain Text', SearchQuery::parse('Plain Text')->like);
    }

    public function test_the_fulltext_query_only_contains_safe_prefix_terms(): void
    {
        $boolean = fn (string $raw): ?string => SearchQuery::parse($raw)->boolean();

        $this->assertSame('+attack* +titan*', $boolean('Attack on Titan'));
        $this->assertSame('+mappa* +studio*', $boolean('MAPPA studio'));
        $this->assertSame('+attack* +titan*', $boolean('+attack -titan "x" (y) ~z *w <v >u @t'));
        $this->assertSame('+one* +two* +three* +four* +five* +six*', $boolean('one two three four five six seven eight'));

        $this->assertNull($boolean('the'));
        $this->assertNull($boolean('86'));
        $this->assertNull($boolean('the 86 of'));

        $this->assertSame('attack titan', SearchQuery::parse('Attack on Titan')->natural());
        $this->assertNull(SearchQuery::parse('86')->natural());
    }

    public function test_non_latin_words_stay_whole(): void
    {
        $this->assertSame(['আমি', 'বাংলা'], SearchQuery::parse('আমি বাংলা')->tokens);
        $this->assertSame(['進撃の巨人'], SearchQuery::parse('進撃の巨人')->tokens);
        $this->assertSame(['café', 'crème'], SearchQuery::parse('Café Crème')->tokens);
    }
}