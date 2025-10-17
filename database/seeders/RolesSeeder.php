<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        if (!class_exists(\Spatie\Permission\Models\Role::class)) {
            return;
        }

        $role = \Spatie\Permission\Models\Role::class;
        foreach (['admin','user'] as $name) {
            $role::query()->firstOrCreate(['name' => $name]);
        }
    }
}
