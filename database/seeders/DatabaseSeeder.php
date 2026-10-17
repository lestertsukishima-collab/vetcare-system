<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Model events create account profiles and authoritative clinical links.
        $this->call(SampleClinicSeeder::class);
    }
}
