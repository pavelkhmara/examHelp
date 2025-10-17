<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $userModel = User::class;

        /** @var \App\Models\User $user */
        $user = $userModel::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ]
        );

        // (Опционально) назначим роль admin, если установлен spatie/laravel-permission
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            $role = \Spatie\Permission\Models\Role::query()->firstOrCreate(['name' => 'admin']);
            if (method_exists($user, 'assignRole')) {
                $user->assignRole($role);
            }
        }
    }
}
