<?php

use Illuminate\Support\Facades\Schedule;

// Schedule::command('demo:seed')->everyTwoHours();
Schedule::command('app:assign-reservation-table')->hourly();

// Clean up old print job files every hour
Schedule::command('cleanup:print-files')->hourly();

// Send Menu PDF daily at each restaurant's configured send_time (checked per minute inside the command)
Schedule::command('app:send-menu-pdf-daily')->everyMinute()->withoutOverlapping();

Schedule::command('app:trial-expire')->daily();
Schedule::command('app:license-expire')->daily();
Schedule::command('app:reset-branch-order-limits')->daily();
Schedule::command('app:hide-cron-job-message')->everyMinute();
Schedule::command('inventory:check-batch-expiry')->daily();

// Generate branch expenses from active recurring templates when next_expense_date is due
Schedule::command('app:process-recurring-expenses')->daily()->withoutOverlapping();

Schedule::command('queue:flush')->weekly();

// Schedule the queue:work command to run without overlapping and with 3 tries
Schedule::command('queue:work database --tries=3 --stop-when-empty')->withoutOverlapping();
