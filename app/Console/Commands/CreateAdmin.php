<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Admin} {--role=super_admin} {--password=}';
    protected $description = 'Create (or reset the password of) a CRM admin account';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! in_array($role, Admin::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', Admin::ROLES));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');
        if (strlen((string) $password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $admin = Admin::query()->firstOrNew(['email' => strtolower((string) $this->argument('email'))]);
        $admin->fill([
            'name' => $admin->exists ? $admin->name : (string) $this->option('name'),
            'role' => $role,
            'is_active' => true,
            'password' => $password,
        ])->save();

        $this->info("Admin {$admin->email} ready ({$admin->role}).");

        return self::SUCCESS;
    }
}
