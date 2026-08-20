<?php

namespace App\Services;

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use App\Models\DereuCompany;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Keeps the local registry of WhatsApp templates in step with Dereu/Meta:
 * registers new templates through the Dereu platform API and mirrors the
 * remote list (including templates created directly in Meta Business
 * Manager) into the whatsapp_templates table.
 */
class WhatsappTemplateRegistry
{
    public function __construct(private readonly DereuPlatformClient $client) {}

    /**
     * Register a template with Meta and store the local pending row. The
     * moderation verdict arrives later via the template_status_update
     * webhook.
     *
     * @param  list<array<string, mixed>>|null  $components  HEADER/FOOTER/BUTTONS in the Meta form.
     * @param  array<string, mixed>|null  $example  Example values for {{n}} placeholders (Meta requires them).
     */
    public function create(
        string $name,
        string $language,
        WhatsappTemplateCategory $category,
        string $body,
        ?array $components = null,
        ?array $example = null,
    ): WhatsappTemplate {
        $this->client->createTemplate($this->externalId(), array_filter([
            'phone_number_id' => $this->phoneNumberId(),
            'name' => $name,
            'language' => $language,
            'category' => $category->value,
            'body' => $body,
            'components' => $components,
            'example' => $example,
        ], fn (mixed $value): bool => $value !== null));

        return WhatsappTemplate::query()->updateOrCreate(
            ['name' => $name, 'language' => $language],
            [
                'category' => $category,
                'status' => WhatsappTemplateStatus::Pending,
                'rejection_reason' => null,
                'body' => $body,
                'components' => $components,
            ],
        );
    }

    /**
     * Delete the template both in Dereu/Meta and locally.
     */
    public function delete(WhatsappTemplate $template): void
    {
        if ($template->dereu_template_id !== null) {
            $this->client->deleteTemplate($this->externalId(), $template->dereu_template_id);
        }

        $template->delete();
    }

    /**
     * Re-pull templates from Meta into Dereu, then mirror the Dereu list
     * locally. Local rows that no longer exist remotely are removed.
     *
     * The sync is the only channel through which Meta's own verdict about
     * a template reaches this system — the template_status_update webhook
     * carries no category — so it reports what it changed instead of
     * changing it silently. Meta re-categorises templates on its own, and
     * utility → marketing multiplies the price of every send through that
     * template roughly fourfold: the operator has to learn about it from
     * somewhere.
     *
     * @return array{total: int, changes: list<string>}
     */
    public function sync(): array
    {
        $externalId = $this->externalId();

        $this->client->syncTemplates($externalId);
        $remote = $this->client->listTemplates($externalId);
        $changes = [];

        foreach ($remote as $item) {
            $existing = WhatsappTemplate::query()
                ->where('name', $item['name'])
                ->where('language', $item['language'])
                ->first();

            $status = $this->remoteStatus($item['status'] ?? null, $existing);
            $category = $this->remoteCategory($item['category'] ?? null, $existing);

            if ($existing !== null && $existing->category !== $category) {
                $changes[] = sprintf('«%s»: категория %s → %s', $item['name'], $existing->category->getLabel(), $category->getLabel());
            }

            if ($existing !== null && $existing->status !== $status) {
                $changes[] = sprintf('«%s»: статус %s → %s', $item['name'], $existing->status->getLabel(), $status->getLabel());
            }

            WhatsappTemplate::query()->updateOrCreate(
                ['name' => $item['name'], 'language' => $item['language']],
                [
                    'category' => $category,
                    'status' => $status,
                    // The list carries no rejection reason — keep the one the
                    // webhook delivered while the template stays rejected.
                    ...($status === WhatsappTemplateStatus::Rejected ? [] : ['rejection_reason' => null]),
                    'body' => $this->bodyText($item['components'] ?? []),
                    'components' => $item['components'] ?? null,
                    'dereu_template_id' => $item['id'] ?? null,
                ],
            );
        }

        $remoteKeys = collect($remote)
            ->map(fn (array $item): string => $item['name'].'|'.$item['language']);

        WhatsappTemplate::query()->get()
            ->reject(fn (WhatsappTemplate $template): bool => $remoteKeys->contains($template->name.'|'.$template->language))
            ->each(function (WhatsappTemplate $template) use (&$changes): void {
                // The same class of invisible change: a flow that suddenly
                // stops sending is otherwise unexplained.
                $changes[] = sprintf('«%s»: удалён в Meta — убран из реестра', $template->name);
                $template->delete();
            });

        if ($changes !== []) {
            Log::warning('Синхронизация изменила реестр шаблонов.', ['changes' => $changes]);
        }

        return ['total' => count($remote), 'changes' => $changes];
    }

    /**
     * Meta's own API spells these enums in upper case, and the sync exists
     * precisely to pull in templates authored straight in Business
     * Manager — so the match is case-insensitive.
     *
     * An unrecognised value must never downgrade a row that is already
     * known: read as «pending», a live «APPROVED» would quietly drop the
     * template out of the approved() scope and stop every notification
     * that goes through it.
     */
    protected function remoteStatus(mixed $value, ?WhatsappTemplate $existing): WhatsappTemplateStatus
    {
        $status = WhatsappTemplateStatus::tryFrom(strtolower(trim((string) $value)));

        if ($status !== null) {
            return $status;
        }

        Log::warning('Unrecognised template status from Dereu.', ['status' => $value]);

        return $existing?->status ?? WhatsappTemplateStatus::Pending;
    }

    /**
     * Same rule as remoteStatus(), and the stake is the price: an
     * unrecognised category read as «utility» would under-report the cost
     * of a marketing template by about four times.
     */
    protected function remoteCategory(mixed $value, ?WhatsappTemplate $existing): WhatsappTemplateCategory
    {
        $category = WhatsappTemplateCategory::tryFrom(strtolower(trim((string) $value)));

        if ($category !== null) {
            return $category;
        }

        Log::warning('Unrecognised template category from Dereu.', ['category' => $value]);

        return $existing?->category ?? WhatsappTemplateCategory::Utility;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    protected function bodyText(array $components): ?string
    {
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return $component['text'] ?? null;
            }
        }

        return null;
    }

    protected function externalId(): string
    {
        $externalId = (string) config('services.dereu.external_id');

        if ($externalId === '') {
            throw new RuntimeException('Dereu external_id is not configured (DEREU_EXTERNAL_ID).');
        }

        return $externalId;
    }

    protected function phoneNumberId(): string
    {
        $company = DereuCompany::current();

        if ($company === null || ! $company->isConnected() || blank($company->phone_number_id)) {
            throw new RuntimeException('WhatsApp number is not connected — cannot manage templates.');
        }

        return $company->phone_number_id;
    }
}
