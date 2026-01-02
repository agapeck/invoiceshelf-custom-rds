<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class TriggerMaintenanceReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:trigger-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate the monthly maintenance reminder notification for all logged-in users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Setting::setSetting('maintenance_reminder_active', 'true');

        $this->info('Maintenance reminder activated. Users will see notifications.');

        return Command::SUCCESS;
    }
}
