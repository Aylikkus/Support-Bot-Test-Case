<?php

namespace Database\Seeders;

use App\Models\Operator;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds the initial operator. Existing operators are left untouched,
     * so a changed password survives restarts.
     */
    public function run(): void
    {
        Operator::firstOrCreate(
            ['name' => 'admin'],
            ['password' => 'admin'],
        );
    }
}
