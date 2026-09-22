<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DemoSupplierCreate extends Command
{
    protected $signature = 'demo:supplier-create';
    protected $description = 'Create the dedicated public recruiter sandbox account without modifying existing users';

    public function handle(): int
    {
        $email = 'recruiter.supplier@example.test';
        $existing = User::where('email', $email)->first();
        if ($existing) {
            if (! $existing->is_demo_sandbox || ! $existing->isSupplier()) {
                $this->error('Email already belongs to another or inactive account. No changes made.');
                return self::FAILURE;
            }
            $this->info('Demo supplier already exists. Credentials and data were preserved.');
            return self::SUCCESS;
        }
        if (User::where('is_demo_sandbox', true)->exists()) {
            $this->error('A sandbox account already exists under another email. No changes made.');
            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill([
            'name' => 'Recruiter Demo Supplier', 'email' => $email,
            'password' => 'RecruiterDemo!2026', 'role' => User::ROLE_SUPPLIER,
            'is_demo_sandbox' => true, 'email_verified_at' => now(),
        ])->save();
        $this->info('Recruiter sandbox account created. Public credentials are documented in README.md.');
        return self::SUCCESS;
    }
}
