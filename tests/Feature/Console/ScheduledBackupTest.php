<?php

namespace Tests\Feature\Console;

use App\Jobs\CreateBackupJob;
use App\Models\FileDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScheduledBackupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        
        // Clear any existing S3/R2 disks to ensure clean state
        FileDisk::whereIn('driver', ['s3', 'r2'])->delete();

        // Default mock: All generic requests return 200 OK
        Http::fake([
            '*' => Http::response('OK', 200),
        ]);
    }

    // Internet connection test removed as it's environment dependent and unmodified logic

    public function test_it_dispatches_backup_for_s3_disk_only()
    {
        $disk = FileDisk::factory()->create(['driver' => 's3', 'name' => 'My S3 Disk']);

        $this->artisan('backup:s3-scheduled')
            ->expectsOutputToContain('Starting scheduled database backup to s3 disk: My S3 Disk')
            ->assertExitCode(0);

        Queue::assertPushed(CreateBackupJob::class, function ($job) use ($disk) {
            $prop = new \ReflectionProperty($job, 'data');
            $prop->setAccessible(true);
            $data = $prop->getValue($job);
            return $data['file_disk_id'] === $disk->id && $data['option'] === 'only-db';
        });
    }

    public function test_it_dispatches_backup_for_r2_disk_only()
    {
        $disk = FileDisk::factory()->create(['driver' => 'r2', 'name' => 'My R2 Disk']);

        $this->artisan('backup:s3-scheduled')
            ->expectsOutputToContain('Starting scheduled database backup to r2 disk: My R2 Disk')
            ->assertExitCode(0);

        Queue::assertPushed(CreateBackupJob::class, function ($job) use ($disk) {
            $prop = new \ReflectionProperty($job, 'data');
            $prop->setAccessible(true);
            $data = $prop->getValue($job);
            return $data['file_disk_id'] === $disk->id;
        });
    }

    public function test_it_dispatches_backup_for_both_s3_and_r2_disks()
    {
        $s3Disk = FileDisk::factory()->create(['driver' => 's3', 'name' => 'S3 Backup']);
        $r2Disk = FileDisk::factory()->create(['driver' => 'r2', 'name' => 'R2 Backup']);
        // Create a non-backup disk to ensure it's ignored
        FileDisk::factory()->create(['driver' => 'local', 'name' => 'Local Disk']);

        $this->artisan('backup:s3-scheduled')
            ->expectsOutputToContain('Found 2 disk(s) for backup.')
            ->expectsOutputToContain('Starting scheduled database backup to s3 disk: S3 Backup')
            ->expectsOutputToContain('Starting scheduled database backup to r2 disk: R2 Backup')
            ->assertExitCode(0);

        Queue::assertPushed(CreateBackupJob::class, 2);
    }

    public function test_it_filters_by_disk_name()
    {
        $s3Disk = FileDisk::factory()->create(['driver' => 's3', 'name' => 'Target Disk']);
        $otherDisk = FileDisk::factory()->create(['driver' => 's3', 'name' => 'Other Disk']);

        $this->artisan('backup:s3-scheduled', ['--disk-name' => 'Target Disk'])
            ->expectsOutputToContain('Starting scheduled database backup to s3 disk: Target Disk')
            ->doesntExpectOutputToContain('Starting scheduled database backup to s3 disk: Other Disk')
            ->assertExitCode(0);

        Queue::assertPushed(CreateBackupJob::class, 1);
    }

    public function test_it_fails_if_no_disks_configured()
    {
        // Ensure no disks exist in the transaction that match
        FileDisk::whereIn('driver', ['s3', 'r2'])->delete();

        $this->artisan('backup:s3-scheduled')
            ->expectsOutputToContain('No S3 or R2 disks configured')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }
}
