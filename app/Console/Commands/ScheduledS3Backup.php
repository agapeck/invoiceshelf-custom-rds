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
                            {--disk-name= : Name of the disk to use (default: all S3/R2 disks)}
                            {--skip-internet-check : Skip internet connectivity check}
                            {--check-interval : Normal: 4h interval. Urgent: if >2 days, backup every internet detection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run scheduled database backup to S3 or R2 if internet is available';

    /**
     * Minimum hours between backups in normal mode (when recent backup exists)
     */
    protected int $minHoursBetweenBackups = 4;

    /**
     * Hours threshold for "urgent" mode - if exceeded, backup every time internet is detected
     */
    protected int $urgentBackupThresholdHours = 48; // 2 days

    /**
     * File to track last backup timestamp (shared with CreateBackupJob for manual backups)
     */
    protected string $lastBackupFile = 'last_s3_backup.txt';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check internet connectivity first (unless skipped)
        if (!$this->option('skip-internet-check') && !$this->hasInternetConnection()) {
            $this->warn('No internet connection available. Skipping scheduled backup.');
            Log::info('Scheduled backup skipped: No internet connection');
            return self::SUCCESS; // Return success so scheduler doesn't retry immediately
        }

        $this->info('Internet connection detected.');

        // Get all S3 and R2 disks
        $query = FileDisk::whereIn('driver', ['s3', 'r2']);
        
        // Filter by disk name if provided
        if ($this->option('disk-name')) {
            $query->where('name', $this->option('disk-name'));
        }
        
        $fileDisks = $query->get();

        if ($fileDisks->isEmpty()) {
            $this->error('No S3 or R2 disks configured. Please configure a backup disk in Settings > File Disk.');
            Log::error('Scheduled backup failed: No S3 or R2 disks configured');
            return self::FAILURE;
        }

        // Check interval since last backup if requested
        if ($this->option('check-interval')) {
            $lastBackupTime = $this->getLastBackupTime();

            if ($lastBackupTime) {
                $hoursSinceLastBackup = $lastBackupTime->diffInHours(now(), true);
                
                // URGENT MODE: If more than 2 days since last backup, run immediately
                if ($hoursSinceLastBackup >= $this->urgentBackupThresholdHours) {
                    $daysSinceLastBackup = round($hoursSinceLastBackup / 24, 1);
                    $this->warn("URGENT: Last backup was {$daysSinceLastBackup} days ago (>{$this->urgentBackupThresholdHours}h threshold). Running backup immediately.");
                    Log::warning("Urgent backup triggered: {$daysSinceLastBackup} days since last backup");
                }
                // NORMAL MODE: Use 4-hour minimum interval
                elseif ($hoursSinceLastBackup < $this->minHoursBetweenBackups) {
                    $this->info("Last backup was {$hoursSinceLastBackup} hours ago. Minimum interval is {$this->minHoursBetweenBackups} hours. Skipping.");
                    Log::info("Scheduled backup skipped: Only {$hoursSinceLastBackup} hours since last backup");
                    return self::SUCCESS;
                } else {
                    $this->info("Last backup was {$hoursSinceLastBackup} hours ago. Proceeding with backup.");
                }
            } else {
                $this->warn('No previous backup found. Running first backup immediately.');
            }
        }

        $hasSuccess = false;
        $totalDisks = $fileDisks->count();
        $this->info("Found {$totalDisks} disk(s) for backup.");

        foreach ($fileDisks as $fileDisk) {
            $this->info("Starting scheduled database backup to {$fileDisk->driver} disk: {$fileDisk->name}");
            Log::info("Starting scheduled database backup to {$fileDisk->driver} disk: {$fileDisk->name}");

            try {
                // Dispatch the backup job (same as manual backup from UI)
                dispatch(new CreateBackupJob([
                    'file_disk_id' => $fileDisk->id,
                    'option' => 'only-db',  // Database only for scheduled backups
                ]))->onQueue(config('backup.queue.name'));

                $this->info("Backup job dispatched successfully for {$fileDisk->name}.");
                Log::info("Scheduled backup job dispatched successfully for {$fileDisk->name}");
                
                $hasSuccess = true;
            } catch (\Exception $e) {
                $this->error("Failed to dispatch backup job for {$fileDisk->name}: " . $e->getMessage());
                Log::error("Scheduled backup failed for {$fileDisk->name}: " . $e->getMessage());
                // Continue to next disk even if one fails
            }
        }

        if ($hasSuccess) {
            // Note: Backup time is recorded by CreateBackupJob upon actual completion,
            // not here on dispatch. This ensures we only track successful backups.
            return self::SUCCESS;
        }

        // If all failed, return failure
        return self::FAILURE;
    }

    /**
     * Check if internet connection is available by trying multiple reliable endpoints.
     * Uses Cloudflare, Google, and Bing DNS as fallbacks for ISP compatibility.
     */
    protected function hasInternetConnection(): bool
    {
        $endpoints = [
            'https://1.1.1.1',           // Cloudflare DNS
            'https://dns.google',         // Google DNS
            'https://www.bing.com',       // Bing (Microsoft)
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::timeout(3)->get($endpoint);
                // Accept any response (including redirects) as proof of connectivity
                if ($response->status() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Try next endpoint
                continue;
            }
        }

        return false;
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
}
