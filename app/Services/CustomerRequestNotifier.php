<?php

namespace App\Services;

use App\Models\CustomerRequest;
use App\Models\WhatsappTemplate;
use App\Services\Bot\NotificationReplyHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the supplier about a new customer request with the
 * [Согласиться]/[Отказаться] buttons: a free session message while the
 * supplier's 24-hour window is open, the paid new_customer_request
 * template otherwise. A delivery problem never breaks the customer flow —
 * the request stays pending and visible to the operator in the admin.
 */
class CustomerRequestNotifier
{
    public const string BUTTON_ACCEPT_TITLE = 'Согласиться';

    public const string BUTTON_DECLINE_TITLE = 'Отказаться';

    public function __construct(private readonly DereuMessenger $messenger) {}

    public function notifySupplier(CustomerRequest $request): void
    {
        $supplier = $request->listing->supplier;
        $acceptId = NotificationReplyHandler::requestAcceptId($request);
        $declineId = NotificationReplyHandler::requestDeclineId($request);

        try {
            if ($supplier->hasOpenSessionWindow()) {
                $this->messenger->sendButtons(
                    $supplier,
                    sprintf(
                        "По вашему объявлению «%s» новая заявка от заказчика: «%s». Готовы взять заказ?",
                        $request->listing->displayName() ?: 'без названия',
                        Str::limit($request->query_text, 300),
                    ),
                    [
                        ['id' => $acceptId, 'title' => self::BUTTON_ACCEPT_TITLE],
                        ['id' => $declineId, 'title' => self::BUTTON_DECLINE_TITLE],
                    ],
                );

                return;
            }

            $template = WhatsappTemplate::query()
                ->approved()
                ->where('name', WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST)
                ->first();

            if ($template === null) {
                Log::warning('No approved new_customer_request template — the supplier was not notified.', [
                    'customer_request_id' => $request->id,
                ]);

                return;
            }

            $this->messenger->sendTemplate(
                $supplier,
                $template,
                [
                    $request->listing->displayName() ?: 'без названия',
                    Str::limit($request->query_text, 200),
                ],
                [$acceptId, $declineId],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to notify the supplier about a customer request.', [
                'customer_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
