<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CoreSeeder::class);

        // Demo tenants/agents only outside production.
        if (ENVIRONMENT !== 'production') {
            $this->call(DemoSeeder::class);
        }
    }
}
