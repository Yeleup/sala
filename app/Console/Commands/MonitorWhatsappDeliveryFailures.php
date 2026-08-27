<?php

namespace App\Console\Commands;

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Support\MetaDeliveryError;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The active alarm of the WhatsApp channel. Failed sends used to surface
 * only as error log lines and passive counters on the chat and report
 * pages — nobody is obliged to open either, and the July 2026 incident
 * (527 of 528 templates killed by code 131042) was noticed late. This
 * sweep recounts the outbound journal over a sliding window and pushes a
 * database notification to every panel administrator when the channel is
 * dying: either failures took over the traffic (a spike), or a single
 * failure carries an account-level Meta code — one of those means nothing
 * is going out to anyone, so it never waits to accumulate a share.
 */
class MonitorWhatsappDeliveryFailures extends Command
{
    protected $signature = 'whatsapp:monitor-delivery-failures';

    protected $description = 'Alert panel administrators about WhatsApp delivery failure spikes and account-level Meta errors';

    public function handle(): int
    {
        $windowMinutes = (int) config('whatsapp-monitoring.window_minutes');
        $windowStart = now()->subMinutes($windowMinutes);

        $this->alertOnFailureSpike($windowStart, $windowMinutes);
        $this->alertOnAccountLevelCodes($windowStart, $windowMinutes);

        return self::SUCCESS;
    }

    /**
     * The share is computed over messages created inside the window, so
     * the numerator and denominator describe the same sends. min_failed
     * keeps the quiet hours honest: at one message per hour a single
     * random failure is a 100% share, not an incident.
     */
    private function alertOnFailureSpike(\DateTimeInterface $windowStart, int $windowMinutes): void
    {
        $counts = ChannelMessage::query()
            ->where('direction', ChannelDirection::Outbound)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [ChannelMessageStatus::Failed->value])
            ->first();

        $total = (int) $counts->total;
        $failed = (int) $counts->failed;

        if ($total === 0 || $failed < (int) config('whatsapp-monitoring.min_failed')) {
            $this->info("Delivery failures in the last {$windowMinutes} minutes: {$failed} of {$total} — below the spike threshold.");

            return;
        }

        $share = $failed / $total;

        if ($share < (float) config('whatsapp-monitoring.failed_share')) {
            $this->info("Delivery failures in the last {$windowMinutes} minutes: {$failed} of {$total} — below the spike threshold.");

            return;
        }

        if (! $this->claimAlert('spike')) {
            $this->info('Failure spike still present — the alert is in its cooldown period.');

            return;
        }

        Notification::make()
            ->danger()
            ->title('WhatsApp: всплеск недоставленных сообщений')
            ->body(sprintf(
                'За последние %d минут не доставлено %d из %d исходящих сообщений (%d%%). Причины отказов — в «Чате WhatsApp».',
                $windowMinutes,
                $failed,
                $total,
                (int) round($share * 100),
            ))
            ->sendToDatabase(User::all());

        $this->warn("Failure spike alert sent: {$failed} of {$total} outbound messages failed.");
    }

    /**
     * Windowed by the moment the verdict landed (updated_at), not by the
     * moment of sending: Meta rejects asynchronously, and an account-level
     * verdict on an hours-old message is exactly the signal this alert
     * exists for. Each code cools down on its own — a billing failure must
     * not silence a later quality-limit one.
     */
    private function alertOnAccountLevelCodes(\DateTimeInterface $windowStart, int $windowMinutes): void
    {
        $reasonsByCode = ChannelMessage::query()
            ->where('direction', ChannelDirection::Outbound)
            ->where('status', ChannelMessageStatus::Failed)
            ->where('updated_at', '>=', $windowStart)
            ->whereNotNull('failure_reason')
            ->pluck('failure_reason')
            ->groupBy(fn (string $reason): ?int => MetaDeliveryError::accountLevelCode($reason))
            ->forget('');

        foreach ($reasonsByCode as $code => $reasons) {
            if (! $this->claimAlert("account-code:{$code}")) {
                $this->info("Account-level code {$code} still present — the alert is in its cooldown period.");

                continue;
            }

            Notification::make()
                ->danger()
                ->title('WhatsApp: сбой на уровне аккаунта')
                ->body(sprintf(
                    'Код %d: %s. Отказов за последние %d минут: %d.',
                    $code,
                    MetaDeliveryError::explain($reasons->first()),
                    $windowMinutes,
                    $reasons->count(),
                ))
                ->sendToDatabase(User::all());

            $this->warn("Account-level alert sent: code {$code}, {$reasons->count()} failures.");
        }
    }

    /**
     * True once per cooldown period per alert key: while the incident is
     * unresolved every sweep would find the same failures and re-send the
     * same notification.
     */
    private function claimAlert(string $key): bool
    {
        return Cache::add(
            "whatsapp-monitoring:{$key}",
            true,
            now()->addMinutes((int) config('whatsapp-monitoring.cooldown_minutes')),
        );
    }
}
