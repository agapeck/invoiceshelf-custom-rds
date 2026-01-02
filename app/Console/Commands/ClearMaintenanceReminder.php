<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class ClearMaintenanceReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:clear-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivate the monthly maintenance reminder notification';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Setting::setSetting('maintenance_reminder_active', 'false');

        $this->info('Maintenance reminder cleared. Users will no longer see notifications.');

        return Command::SUCCESS;
    }
}
