<?php

use App\Console\Commands\MonitorWhatsappDeliveryFailures;
use App\Console\Commands\ProcessScenarioRunTimeouts;
use App\Console\Commands\RedispatchUnprocessedDereuEvents;
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

// Reply timeouts of scenario runs are hour-grained, so an hourly sweep
// is enough.
Schedule::command(ProcessScenarioRunTimeouts::class)->hourly();

// Webhook events whose job was lost (queue hiccup at dispatch, a worker
// killed hard): Dereu's redelivery is deduplicated against the stored row
// and never re-dispatches, so only this sweep stands between such an event
// and the bot silently ignoring the message.
Schedule::command(RedispatchUnprocessedDereuEvents::class)->everyFiveMinutes();

// The active alarm of the WhatsApp channel: failed counters on the chat
// and report pages are passive, and the July 2026 incident (527 of 528
// templates killed by code 131042) was noticed late. Five minutes keeps
// the reaction time close to the alert's own sliding window.
Schedule::command(MonitorWhatsappDeliveryFailures::class)->everyFiveMinutes();
