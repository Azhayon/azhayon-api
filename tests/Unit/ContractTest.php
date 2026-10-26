<?php

namespace Tests\Unit;

use App\Support\Api\ApiTypes;
use App\Support\Api\Contract;
use PHPUnit\Framework\TestCase;

class ContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function adsOn(): array
    {
        return [
            'enabled' => true,
            'client' => 'ca-pub-1234567890123456',
            'placements' => [
                'sidebar' => ['enabled' => true, 'slot' => '123456'],
                'in_article' => ['enabled' => false, 'slot' => null],
                'anchor' => ['enabled' => false, 'slot' => null],
            ],
            'rules' => ['in_article_every' => 4, 'in_article_max' => 3],
        ];
    }

    /** @return array<string, mixed> */
    private function site(array $ads): array
    {
        return ['data' => [
            'name' => 'Azhayon Media',
            'tagline' => 'Tagline',
            'url' => 'https://example.test',
            'ads' => $ads,
            'categories' => [['name' => 'Anime', 'slug' => 'anime']],
        ]];
    }

    public function test_matching_data_passes(): void
    {
        $this->assertSame([], Contract::validate('ErrorResponse', ['message' => 'Not found.']));
        $this->assertSame([], Contract::validate('HealthResponse', ['status' => 'ok', 'database' => 'ok']));
        $this->assertSame([], Contract::validate('SiteResponse', $this->site(['enabled' => false])));
        $this->assertSame([], Contract::validate('SiteResponse', $this->site($this->adsOn())));
    }

    public function test_problems_are_reported_with_their_location(): void
    {
        $errors = Contract::validate('PostsResponse', [
            'data' => [['slug' => 5]],
            'meta' => ['page' => 1, 'per_page' => 12, 'total' => 1, 'last_page' => 1, 'extra' => true],
        ]);

        $text = implode("\n", $errors);

        $this->assertStringContainsString('$.data[0].slug: expected string, got int', $text);
        $this->assertStringContainsString('$.data[0].title: missing', $text);
        $this->assertStringContainsString('$.meta.extra: not in the contract', $text);

        $this->assertNotSame([], Contract::validate('ErrorResponse', ['message' => 'x', 'debug' => 'leaked']));
        $this->assertNotSame([], Contract::validate('ErrorResponse', []));
        $this->assertNotSame([], Contract::validate('ErrorResponse', ['message' => null]));
        $this->assertNotSame([], Contract::validate('ErrorResponse', null));
    }

    public function test_unions_nullables_maps_and_enums(): void
    {
        $this->assertNotSame([], Contract::validate('HealthResponse', ['status' => 'weird', 'database' => 'ok']));

        // Union: the value must fit one alternative completely.
        $this->assertNotSame([], Contract::validate('SiteResponse', $this->site(['enabled' => true])));
        $this->assertNotSame([], Contract::validate('SiteResponse', $this->site(['enabled' => 'yes'])));
        $this->assertNotSame([], Contract::validate('SiteResponse', $this->site(['enabled' => false, 'client' => 'x'])));

        // Nullable: null is fine where allowed and wrong where not.
        $link = ['title' => 'T', 'slug' => 's', 'path' => '/blog/s'];
        $series = ['title' => 'S', 'slug' => 's', 'path' => '/series/s', 'total' => 3, 'previous' => null, 'next' => $link];
        $this->assertSame([], Contract::validate('PostSeries', $series));
        $this->assertNotSame([], Contract::validate('PostSeries', ['total' => null] + $series));

        // Maps and lists.
        $this->assertSame([], Contract::validate('ValidationErrorResponse', ['message' => 'Bad.', 'errors' => ['q' => ['Too short.']]]));
        $this->assertNotSame([], Contract::validate('ValidationErrorResponse', ['message' => 'Bad.', 'errors' => ['q' => 'Too short.']]));
        $this->assertNotSame([], Contract::validate('ValidationErrorResponse', ['message' => 'Bad.', 'errors' => [['Too short.']]]));

        // "number" takes whole numbers and decimals; "int" does not take decimals.
        $rating = ['count' => 3];
        $this->assertSame([], Contract::validate('PostRating', ['average' => 4, ...$rating]));
        $this->assertSame([], Contract::validate('PostRating', ['average' => 4.5, ...$rating]));
        $this->assertNotSame([], Contract::validate('PostRating', ['average' => 4.5, 'count' => 3.5]));
    }

    public function test_every_reference_resolves_and_types_are_generated(): void
    {
        $definitions = Contract::definitions();

        $references = function (mixed $node) use (&$references): array {
            if (is_array($node)) {
                return array_merge([], ...array_map($references, array_values($node)));
            }

            preg_match_all('/@([A-Za-z0-9]+)/', (string) $node, $matches);

            return $matches[1];
        };

        foreach ($definitions as $name => $shape) {
            foreach ($references($shape) as $reference) {
                $this->assertArrayHasKey($reference, $definitions, "{$name} refers to an unknown definition");
            }
        }

        $ts = ApiTypes::generate();

        $this->assertStringContainsString('export interface PostCard {', $ts);
        $this->assertStringContainsString("type: 'news' | 'review' | 'explainer' | 'list';", $ts);
        $this->assertStringContainsString('image: ApiImage | null;', $ts);
        $this->assertStringContainsString('ads: AdsOff | AdsOn;', $ts);
        $this->assertStringContainsString('errors: Record<string, string[]>;', $ts);
        $this->assertStringContainsString('content: ContentNode[];', $ts);
        $this->assertStringContainsString('export type ContentNode =', $ts);
        $this->assertStringNotContainsString('interface ContentNode', $ts);
        $this->assertStringContainsString('export interface PostDetail {', $ts);
        $this->assertStringNotContainsString('@', str_replace('@Name', '', preg_replace('#//.*#', '', $ts)));
    }
}