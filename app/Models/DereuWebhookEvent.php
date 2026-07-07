<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DereuWebhookEvent extends Model
{
    /** @use HasFactory<\Database\Factories\DereuWebhookEventFactory> */
    use HasFactory;

    protected $fillable = [
        'event',
        'event_id',
        'dedupe_key',
        'company_id',
        'phone_number_id',
        'wamid',
        'payload',
        'processed_at',
    ];

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
