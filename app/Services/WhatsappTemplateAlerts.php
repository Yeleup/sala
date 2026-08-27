<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappTemplate;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Alerts about the WhatsApp template registry that must not depend on who
 * happens to be looking: a flash notification reaches only the operator who
 * pressed the button, and the scheduled sync has no operator at all. Every
 * alert therefore lands as a database notification for each administrator
 * (the panel bell) and as a line in the server error log the monitoring
 * watches.
 */
class WhatsappTemplateAlerts
{
    /**
     * Meta changed the registry behind the project's back: re-categorised
     * or re-moderated a template, or deleted one. A utility → marketing
     * re-classification multiplies the price of every send through that
     * template roughly fourfold.
     *
     * @param  list<string>  $changes
     */
    public function registryChanged(array $changes): void
    {
        Log::error('Синхронизация изменила реестр шаблонов WhatsApp.', ['changes' => $changes]);

        $this->notifyAdmins(
            Notification::make()
                ->title('Meta изменила шаблоны WhatsApp')
                ->body(implode("\n", $changes))
                ->warning(),
        );
    }

    /**
     * Meta rejected a template: every notification flow that sends through
     * it outside the 24-hour window is silently dead until the operator
     * reacts.
     */
    public function templateRejected(WhatsappTemplate $template): void
    {
        Log::error('Meta отклонила шаблон WhatsApp.', [
            'name' => $template->name,
            'language' => $template->language,
            'reason' => $template->rejection_reason,
        ]);

        $this->notifyAdmins(
            Notification::make()
                ->title("Meta отклонила шаблон «{$template->name}»")
                ->body($template->rejection_reason ?? 'Причину Meta не сообщила.')
                ->danger(),
        );
    }

    protected function notifyAdmins(Notification $notification): void
    {
        $notification->sendToDatabase(User::all());
    }
}
