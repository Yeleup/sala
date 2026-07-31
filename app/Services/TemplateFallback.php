<?php

namespace App\Services;

use App\Models\WhatsappTemplate;

/**
 * The paid-template plan B a session notification carries into the channel
 * journal: everything needed to re-send the notification as an approved
 * Template Message when Meta rejects the session message asynchronously as
 * outside the 24-hour window (re-engagement, error 131047).
 *
 * Only session sends carry one — a template send never does, so a failed
 * re-send cannot trigger another re-send.
 */
final readonly class TemplateFallback
{
    /**
     * @param  list<string>  $bodyParameters
     * @param  list<string>  $buttonPayloads
     */
    public function __construct(
        public WhatsappTemplate $template,
        public array $bodyParameters = [],
        public array $buttonPayloads = [],
    ) {}

    /**
     * @return array{whatsapp_template_id: int, body_parameters: list<string>, button_payloads: list<string>}
     */
    public function toArray(): array
    {
        return [
            'whatsapp_template_id' => $this->template->id,
            'body_parameters' => $this->bodyParameters,
            'button_payloads' => $this->buttonPayloads,
        ];
    }
}
