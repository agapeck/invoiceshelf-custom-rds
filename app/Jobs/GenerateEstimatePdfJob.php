<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateEstimatePdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $estimate;

    public $deleteExistingFile;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($estimate, $deleteExistingFile = false)
    {
        $this->estimate = $estimate;
        $this->deleteExistingFile = $deleteExistingFile;
    }

    /**
     * Execute the job.
     */
    public function handle(): int
    {
        $this->estimate->generatePDF('estimate', $this->estimate->estimate_number, $this->deleteExistingFile);

        return 0;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to generate estimate PDF', [
            'estimate_id' => $this->estimate->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
