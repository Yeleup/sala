<?php

namespace App\Services;

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use App\Models\DereuCompany;
use App\Models\WhatsappTemplate;
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
     * Returns the number of templates in the registry after the sync.
     */
    public function sync(): int
    {
        $externalId = $this->externalId();

        $this->client->syncTemplates($externalId);
        $remote = $this->client->listTemplates($externalId);

        foreach ($remote as $item) {
            $status = WhatsappTemplateStatus::tryFrom($item['status']) ?? WhatsappTemplateStatus::Pending;

            WhatsappTemplate::query()->updateOrCreate(
                ['name' => $item['name'], 'language' => $item['language']],
                [
                    'category' => WhatsappTemplateCategory::tryFrom($item['category']) ?? WhatsappTemplateCategory::Utility,
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
            ->each(fn (WhatsappTemplate $template) => $template->delete());

        return count($remote);
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
