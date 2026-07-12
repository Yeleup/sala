<?php

use App\Console\Commands\RunListingRenewalCycle;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The 30-day relevance cycle: 04:00 UTC ≈ 10:00 по Астане, so the poll
// lands in the supplier's morning.
Schedule::command(RunListingRenewalCycle::class)->dailyAt('04:00');
