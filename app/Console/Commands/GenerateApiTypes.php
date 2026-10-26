<?php

namespace App\Console\Commands;

use App\Support\Api\ApiTypes;
use Illuminate\Console\Command;

class GenerateApiTypes extends Command
{
    protected $signature = 'api:types {path? : Where to write the file (default: docs/api-types.ts)}';

    protected $description = 'Generate the TypeScript types for the public API from its contract';

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('docs/api-types.ts');

        file_put_contents($path, ApiTypes::generate());

        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }
}