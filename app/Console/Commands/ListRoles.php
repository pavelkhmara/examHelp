<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class ListRoles extends Command
{
    protected $signature = 'roles:list';
    protected $description = 'List all roles';

    public function handle()
    {
        $roles = Role::pluck('name')->toArray();
        $this->info('Roles: ' . implode(', ', $roles));
        return 0;
    }
}