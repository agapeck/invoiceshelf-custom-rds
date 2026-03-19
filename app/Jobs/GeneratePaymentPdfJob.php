<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GeneratePaymentPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $payment;

    public $deleteExistingFile;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($payment, $deleteExistingFile = false)
    {
        $this->payment = $payment;
        $this->deleteExistingFile = $deleteExistingFile;
    }

    /**
     * Execute the job.
     */
    public function handle(): int
    {
        $this->payment->generatePDF('payment', $this->payment->payment_number, $this->deleteExistingFile);

        return 0;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to generate payment PDF', [
            'payment_id' => $this->payment->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
