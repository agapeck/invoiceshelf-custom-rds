<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Estimate;
use App\Models\Transaction;
use App\Models\Appointment;

class RegenerateMissingHashesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hash:regenerate-missing {--model= : Optional model to target (Invoice,Payment,Estimate,Transaction,Appointment)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Regenerate missing unique_hash values for models that use the GeneratesHashTrait';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $targetModel = $this->option('model');
        
        $models = [
            'Invoice' => Invoice::class,
            'Payment' => Payment::class,
            'Estimate' => Estimate::class,
            'Transaction' => Transaction::class,
            'Appointment' => Appointment::class,
        ];

        if ($targetModel) {
            if (! isset($models[$targetModel])) {
                $this->error("Invalid model specified. Available models: " . implode(', ', array_keys($models)));
                return 1;
            }
            $models = [$targetModel => $models[$targetModel]];
        }

        $this->info('Starting hash regeneration...');

        foreach ($models as $name => $class) {
            $this->info("Processing {$name}...");
            
            // Verify the model uses the trait
            if (! method_exists($class, 'regenerateMissingHashes')) {
                $this->warn("Model {$name} does not seem to verify GeneratesHashTrait methods.");
                continue;
            }

            $results = $class::regenerateMissingHashes();
            
            $this->info("  - Success: {$results['success']}");
            if ($results['failed'] > 0) {
                $this->error("  - Failed: {$results['failed']}");
            }
        }

        $this->info('Hash regeneration complete.');
        return 0;
    }
}
