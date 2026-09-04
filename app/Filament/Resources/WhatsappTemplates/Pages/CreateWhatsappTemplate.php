<?php

namespace App\Filament\Resources\WhatsappTemplates\Pages;

use App\Enums\WhatsappTemplateCategory;
use App\Exceptions\OutboundRequestBlocked;
use App\Filament\Resources\WhatsappTemplates\WhatsappTemplateResource;
use App\Services\WhatsappTemplateRegistry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

class CreateWhatsappTemplate extends CreateRecord
{
    protected static string $resource = WhatsappTemplateResource::class;

    /**
     * Registration goes through Dereu to Meta; only after Dereu accepts the
     * template does the local pending row appear. A Meta refusal (422) is
     * shown on the form instead of a server error.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $quickReplies = array_values(array_filter((array) ($data['quick_replies'] ?? []), fn (mixed $text): bool => filled($text)));
        $examples = array_values(array_filter((array) ($data['body_examples'] ?? []), fn (mixed $value): bool => filled($value)));

        $components = $quickReplies === [] ? null : [[
            'type' => 'BUTTONS',
            'buttons' => array_map(
                fn (string $text): array => ['type' => 'QUICK_REPLY', 'text' => $text],
                $quickReplies,
            ),
        ]];

        try {
            return app(WhatsappTemplateRegistry::class)->create(
                name: $data['name'],
                language: $data['language'],
                category: $data['category'] instanceof WhatsappTemplateCategory
                    ? $data['category']
                    : WhatsappTemplateCategory::from($data['category']),
                body: $data['body'],
                components: $components,
                example: $examples === [] ? null : ['body_text' => [$examples]],
            );
        } catch (OutboundRequestBlocked $e) {
            // The template never left the machine, so this is not a refusal
            // by Meta and must not be shown on the form as one.
            Notification::make()
                ->title('WhatsApp недоступен с этой машины')
                ->body('Локальная установка работает на копии боевых данных, поэтому шаблон не отправлен в Dereu и не создан. Зарегистрируйте его на боевом контуре.')
                ->warning()
                ->send();

            throw new Halt;
        } catch (RequestException $e) {
            throw ValidationException::withMessages([
                'data.body' => 'Dereu/Meta отклонили шаблон: '.($e->response->json('message') ?? $e->getMessage()),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
