<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BaseDataSeeder::class);

        // Fake data only on a local machine.
        if (app()->environment('local')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}