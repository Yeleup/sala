<?php

namespace App\Models;

use Database\Factories\DereuWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook event forwarded by Dereu (inbound messages and delivery statuses).
 *
 * company_id holds Dereu's internal dereu_company_id, not our external_id.
 */
#[Fillable(['event', 'event_id', 'dedupe_key', 'company_id', 'phone_number_id', 'wamid', 'payload', 'processed_at'])]
class DereuWebhookEvent extends Model
{
    /** @use HasFactory<DereuWebhookEventFactory> */
    use HasFactory;

    /**
     * @return array{payload: 'array', processed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
