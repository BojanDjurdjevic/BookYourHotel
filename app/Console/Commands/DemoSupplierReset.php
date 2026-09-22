<?php

namespace App\Console\Commands;

use App\Services\DemoSupplierSandbox;
use Illuminate\Console\Command;

class DemoSupplierReset extends Command
{
    protected $signature = 'demo:supplier-reset';
    protected $description = 'Remove expired recruiter sandbox hotels, retaining the account and any booking history';

    public function handle(DemoSupplierSandbox $sandbox): int
    {
        $result = $sandbox->reset();
        $this->info("Deleted {$result['deleted']} expired sandbox hotel(s).");
        foreach ($result['skipped'] as $id => $reason) $this->warn("Skipped hotel $id: $reason");
        return $result['skipped'] ? self::FAILURE : self::SUCCESS;
    }
}
