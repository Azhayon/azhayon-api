<?php

namespace Tests\Feature\Api;

use App\Support\Api\ApiTypes;
use Tests\TestCase;

class ApiTypesTest extends TestCase
{
    public function test_the_committed_typescript_file_matches_the_contract(): void
    {
        $path = base_path('docs/api-types.ts');

        $this->assertFileExists($path, 'Generate it with: php artisan api:types');

        $normalise = fn (string $text): string => str_replace("\r\n", "\n", $text);

        $this->assertSame(
            $normalise(ApiTypes::generate()),
            $normalise((string) file_get_contents($path)),
            'docs/api-types.ts is out of date. Regenerate it with: php artisan api:types',
        );
    }
}