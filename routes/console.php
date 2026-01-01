<?php

use App\Models\CompanySetting;
use App\Models\FileDisk;
use App\Models\RecurringInvoice;
use App\Space\InstallUtils;
use Illuminate\Support\Facades\Schedule;

// Only run in demo environment
if (config('app.env') === 'demo') {
    Schedule::command('reset:app --force')
        ->daily()
        ->runInBackground()
        ->withoutOverlapping();
}

if (InstallUtils::isDbCreated()) {
    Schedule::command('check:invoices:status')
        ->daily();

    Schedule::command('check:estimates:status')
        ->daily();

    // Wrap in try-catch to handle cases where the database schema is incomplete
    // (e.g., during fresh migrations when deleted_at column doesn't exist yet)
    try {
        $recurringInvoices = RecurringInvoice::where('status', 'ACTIVE')->get();
        foreach ($recurringInvoices as $recurringInvoice) {
            $timeZone = CompanySetting::getSetting('time_zone', $recurringInvoice->company_id);

            Schedule::call(function () use ($recurringInvoice) {
                $recurringInvoice->generateInvoice();
            })->cron($recurringInvoice->frequency)->timezone($timeZone);
        }
    } catch (\Exception $e) {
        // Silently ignore if table structure is incomplete (e.g., during migrations)
        // The scheduler will pick up recurring invoices on the next artisan call
    }

    /*
    |--------------------------------------------------------------------------
    | Automatic S3 Database Backups (Internet Detection Based)
    |--------------------------------------------------------------------------
    |
    | Instead of fixed times, backups run every 30 minutes during business hours
    | (8 AM - 10 PM) but only actually back up when:
    |   1. Internet is available
    |   2. At least 4 hours have passed since the last successful backup
    |
    | This ensures backups happen opportunistically when internet is detected,
    | rather than failing silently at fixed times when offline.
    |
    */
    try {
        // Only schedule if an S3 disk is configured
        $hasS3Disk = FileDisk::where('driver', 's3')->exists();
        
        if ($hasS3Disk) {
            // Get company timezone (default to Africa/Nairobi for East Africa)
            $timeZone = CompanySetting::getSetting('time_zone', 1) ?? 'Africa/Nairobi';

            // Run backup check every 30 minutes during business hours (8 AM - 10 PM)
            // The backup command itself checks:
            //   - Internet connectivity
            //   - Time since last backup (only backs up if > 4 hours since last)
            Schedule::command('backup:s3-scheduled --check-interval')
                ->everyThirtyMinutes()
                ->between('08:00', '22:00')
                ->timezone($timeZone)
                ->withoutOverlapping()
                ->runInBackground()
                ->description('S3 Backup - Internet Detection Check (every 30 min, 8AM-10PM)');
        }
    } catch (\Exception $e) {
        // Silently ignore if S3 disk table doesn't exist yet
    }
}
