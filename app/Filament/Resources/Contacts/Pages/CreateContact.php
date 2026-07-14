<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * An operator-created contact has no inbound messages yet, so its 24-hour
 * window is closed: proactive notifications go out as template messages
 * until the contact writes to the bot.
 */
class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;
}
