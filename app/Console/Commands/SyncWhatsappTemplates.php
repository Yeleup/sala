<?php

namespace App\Console\Commands;

use App\Models\DereuCompany;
use App\Services\WhatsappTemplateRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily pull of the template registry from Meta. The manual «Sync»
 * button is the only other path a category re-classification can enter the
 * system by (the template_status_update webhook carries no category), and
 * nobody presses it on a schedule: a utility → marketing re-classification
 * — roughly a fourfold price — stayed invisible until someone happened to.
 * On changes the registry itself alerts every administrator and the error
 * log; this command only makes sure the pull happens.
 */
#[Signature('whatsapp:sync-templates')]
#[Description('Синхронизировать реестр WhatsApp-шаблонов с Meta через Dereu')]
class SyncWhatsappTemplates extends Command
{
    public function handle(WhatsappTemplateRegistry $registry): int
    {
        if (DereuCompany::current()?->isConnected() !== true) {
            $this->info('Номер WhatsApp не подключён — синхронизация шаблонов пропущена.');

            return self::SUCCESS;
        }

        try {
            $report = $registry->sync();
        } catch (Throwable $e) {
            Log::error('Плановая синхронизация шаблонов WhatsApp не удалась.', ['exception' => $e]);
            $this->error("Синхронизация не удалась: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Шаблонов синхронизировано: %d, изменений: %d.', $report['total'], count($report['changes'])));

        return self::SUCCESS;
    }
}
