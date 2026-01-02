<?php

namespace App\Jobs;

use App\Models\FileDisk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data = '')
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $fileDisk = FileDisk::find($this->data['file_disk_id']);

        if (!$fileDisk) {
            throw new \RuntimeException(
                "Backup failed: File disk (ID: {$this->data['file_disk_id']}) was deleted or not found."
            );
        }

        $prefix = env('DYNAMIC_DISK_PREFIX', 'temp_');
        
        // Purge any existing instances of this dynamic disk to ensure we use fresh credentials
        // This is critical when running multiple backups sequentially (e.g. ScheduleS3Backup command)
        Storage::purge($prefix.$fileDisk->driver);

        $fileDisk->setConfig();

        config(['backup.backup.destination.disks' => [$prefix.$fileDisk->driver]]);

        $config = Config::fromArray(config('backup'));
        $backupJob = BackupJobFactory::createFromConfig($config);
        if (! defined('SIGINT')) {
            $backupJob->disableSignals();
        }

        if ($this->data['option'] === 'only-db') {
            $backupJob->dontBackupFilesystem();
        }

        if ($this->data['option'] === 'only-files') {
            $backupJob->dontBackupDatabases();
        }

        if (! empty($this->data['option'])) {
            $prefix = str_replace('_', '-', $this->data['option']).'-';

            $backupJob->setFilename($prefix.date('Y-m-d-H-i-s').'.zip');
        }

        try {
            $backupJob->run();
            Log::info("Backup completed successfully to {$fileDisk->driver} disk: {$fileDisk->name}");
        } catch (\Exception $e) {
            Log::error("Backup failed for {$fileDisk->driver} disk {$fileDisk->name}: " . $e->getMessage());
            throw $e; // Re-throw so Laravel marks the job as failed
        }
    }
}
