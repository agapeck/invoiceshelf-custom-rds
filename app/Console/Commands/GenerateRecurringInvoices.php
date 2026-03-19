<?php

namespace App\Console\Commands;

use App\Models\RecurringInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateRecurringInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:generate-recurring {--company= : Restrict to a single company id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate due recurring invoices in a chunked, lock-safe manner';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $companyId = $this->option('company');
        $generatedCount = 0;

        $query = RecurringInvoice::query()
            ->where('status', RecurringInvoice::ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where(function ($innerQuery) use ($now) {
                $innerQuery->whereNull('next_invoice_at')
                    ->orWhere('next_invoice_at', '<=', $now);
            })
            ->orderBy('id');

        if ($companyId) {
            $query->where('company_id', (int) $companyId);
        }

        $query->chunkById(100, function ($recurringInvoices) use (&$generatedCount) {
            foreach ($recurringInvoices as $recurringInvoice) {
                try {
                    $recurringInvoice->generateInvoice();
                    $generatedCount++;
                } catch (\Throwable $e) {
                    Log::error('Failed generating recurring invoice', [
                        'recurring_invoice_id' => $recurringInvoice->id,
                        'company_id' => $recurringInvoice->company_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Processed {$generatedCount} recurring invoice entries.");

        return self::SUCCESS;
    }
}

