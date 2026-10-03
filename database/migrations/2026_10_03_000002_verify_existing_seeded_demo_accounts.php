<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only installations with the known catalog seed marker and exact seeded identities.
        if (! DB::table('demo_seed_runs')->where('name', 'portfolio-v1')->exists()) {
            return;
        }
        $accounts = [];
        for ($i = 1; $i <= 10; $i++) {
            $accounts[] = [sprintf('supplier%02d', $i), 'Demo Supplier '.$i, 'supplier'];
        }
        foreach (['user', 'admin', 'superadmin'] as $role) {
            $accounts[] = [$role, 'Demo '.ucfirst($role), $role];
        }
        for ($i = 1; $i <= 20; $i++) {
            $accounts[] = ['traveler'.$i, 'Demo Traveler '.$i, 'user'];
        }
        foreach ($accounts as [$local, $name, $role]) {
            DB::table('users')->where('email', $local.'@demo.bookyourhotel.test')
                ->where('name', $name)->where('role', $role)->whereNull('email_verified_at')
                ->update(['email_verified_at' => now()]);
        }
    }

    public function down(): void
    {
        // Ownership flags are deliberately not revoked on rollback.
    }
};
