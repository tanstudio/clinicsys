<?php

namespace App\Console\Commands;

use App\Messaging\Delivery;
use App\Messaging\NotificationQueue;
use Illuminate\Console\Command;

class ClinicMessages extends Command
{
    protected $signature = 'clinic:messages';
    protected $description = 'Collect clinic notifications and submit due messages';

    public function handle(NotificationQueue $queue, Delivery $delivery): int
    {
        $queue->collect();
        $delivery->consume();
        return self::SUCCESS;
    }
}
