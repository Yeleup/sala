<?php

namespace App\Exceptions;

use App\Models\Contact;
use RuntimeException;

/**
 * A free session message was about to be sent outside the contact's
 * 24-hour window — WhatsApp would not deliver it. Callers that can fall
 * back to a paid Template Message should catch this (or use
 * DereuMessenger::sendTextOrTemplate()).
 */
class SessionWindowClosed extends RuntimeException
{
    public function __construct(public readonly Contact $contact)
    {
        parent::__construct(sprintf(
            'The 24-hour session window of contact %s is closed — only template messages are deliverable.',
            $contact->phone,
        ));
    }
}
