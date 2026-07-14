<?php

namespace App\Console\Commands;

use App\Enums\ScenarioRunStatus;
use App\Models\ScenarioRun;
use App\Services\Bot\ScenarioRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Advances waiting scenario runs whose reply timeout has passed along
 * their «timeout» branch (or quietly finishes them when it is not
 * wired). Each run is claimed atomically, so a reply arriving in the
 * same moment is not processed twice.
 */
#[Signature('bot:process-run-timeouts')]
#[Description('Провести запуски сценариев с истёкшим таймаутом ожидания по ветке таймаута')]
class ProcessScenarioRunTimeouts extends Command
{
    public function handle(ScenarioRunner $runner): int
    {
        $processed = 0;

        ScenarioRun::query()
            ->where('status', ScenarioRunStatus::Active)
            ->where('timeout_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(function (ScenarioRun $run) use ($runner, &$processed): void {
                $claimed = ScenarioRun::query()
                    ->whereKey($run->id)
                    ->where('status', ScenarioRunStatus::Active)
                    ->whereNotNull('timeout_at')
                    ->update(['timeout_at' => null]);

                if ($claimed === 0) {
                    return;
                }

                $runner->handleTimeout($run->refresh());
                $processed++;
            });

        $this->info("Таймаутов обработано: {$processed}.");

        return self::SUCCESS;
    }
}
