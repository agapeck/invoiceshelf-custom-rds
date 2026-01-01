<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackupJob;
use App\Models\FileDisk;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScheduledS3Backup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:s3-scheduled 
                            {--disk-name= : Name of the S3 disk to use (default: first S3 disk found)}
                            {--skip-internet-check : Skip internet connectivity check}
                            {--check-interval : Only backup if 4+ hours since last successful backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run scheduled database backup to S3 if internet is available';

    /**
     * Minimum hours between backups when using --check-interval
     */
    protected int $minHoursBetweenBackups = 4;

    /**
     * File to track last backup timestamp
     */
    protected string $lastBackupFile = 'last_s3_backup.txt';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check internet connectivity first (unless skipped)
        if (!$this->option('skip-internet-check') && !$this->hasInternetConnection()) {
            $this->warn('No internet connection available. Skipping S3 backup.');
            Log::info('Scheduled S3 backup skipped: No internet connection');
            return self::SUCCESS; // Return success so scheduler doesn't retry immediately
        }

        $this->info('Internet connection detected.');

        // Find the S3 disk
        $diskName = $this->option('disk-name');
        
        if ($diskName) {
            $fileDisk = FileDisk::where('driver', 's3')
                ->where('name', $diskName)
                ->first();
        } else {
            // Get first available S3 disk
            $fileDisk = FileDisk::where('driver', 's3')->first();
        }

        if (!$fileDisk) {
            $this->error('No S3 disk configured. Please configure an S3 backup disk in Settings > File Disk.');
            Log::error('Scheduled S3 backup failed: No S3 disk configured');
            return self::FAILURE;
        }

        // Check interval since last backup if requested
        if ($this->option('check-interval')) {
            $lastBackupTime = $this->getLastBackupTime();

            if ($lastBackupTime) {
                $hoursSinceLastBackup = now()->diffInHours($lastBackupTime);
                
                if ($hoursSinceLastBackup < $this->minHoursBetweenBackups) {
                    $this->info("Last backup was {$hoursSinceLastBackup} hours ago. Minimum interval is {$this->minHoursBetweenBackups} hours. Skipping.");
                    Log::info("Scheduled S3 backup skipped: Only {$hoursSinceLastBackup} hours since last backup");
                    return self::SUCCESS;
                }
                
                $this->info("Last backup was {$hoursSinceLastBackup} hours ago. Proceeding with backup.");
            } else {
                $this->info('No previous backup found. Proceeding with first backup.');
            }
        }

        $this->info("Starting scheduled database backup to S3 disk: {$fileDisk->name}");
        Log::info("Starting scheduled S3 backup to disk: {$fileDisk->name}");

        try {
            // Dispatch the backup job (same as manual backup from UI)
            dispatch(new CreateBackupJob([
                'file_disk_id' => $fileDisk->id,
                'option' => 'only-db',  // Database only for scheduled backups
            ]))->onQueue(config('backup.queue.name'));

            // Record the backup time
            $this->recordBackupTime();

            $this->info('Backup job dispatched successfully.');
            Log::info('Scheduled S3 backup job dispatched successfully');
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to dispatch backup job: ' . $e->getMessage());
            Log::error('Scheduled S3 backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Check if internet connection is available by pinging AWS S3 endpoint
     */
    protected function hasInternetConnection(): bool
    {
        try {
            // Try to reach AWS S3's endpoint (lightweight check)
            $response = Http::timeout(5)->get('https://s3.amazonaws.com');
            return $response->successful() || $response->status() === 403; // 403 is expected without auth
        } catch (\Exception $e) {
            // Try a fallback check with Google DNS
            try {
                $response = Http::timeout(5)->get('https://dns.google');
                return $response->successful();
            } catch (\Exception $e) {
                return false;
            }
        }
    }

    /**
     * Get the timestamp of the last successful backup
     */
    protected function getLastBackupTime(): ?Carbon
    {
        try {
            if (Storage::disk('local')->exists($this->lastBackupFile)) {
                $timestamp = Storage::disk('local')->get($this->lastBackupFile);
                return Carbon::parse(trim($timestamp));
            }
        } catch (\Exception $e) {
            Log::warning('Failed to read last backup time: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Record the current time as the last backup time
     */
    protected function recordBackupTime(): void
    {
        try {
            Storage::disk('local')->put($this->lastBackupFile, now()->toIso8601String());
        } catch (\Exception $e) {
            Log::warning('Failed to record backup time: ' . $e->getMessage());
        }
    }
}
