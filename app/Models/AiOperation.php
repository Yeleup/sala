<?php

namespace App\Models;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use Database\Factories\AiOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business-level AI operation (extraction, transcription) grouping the
 * real provider calls it took. Created by AiAudit::run().
 */
#[Fillable(['operation', 'status', 'error', 'contact_id', 'bot_session_id', 'channel_message_id', 'listing_id'])]
class AiOperation extends Model
{
    /** @use HasFactory<AiOperationFactory> */
    use HasFactory;

    /** @return HasMany<AiAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AiAttempt::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @return BelongsTo<ChannelMessage, $this> */
    public function channelMessage(): BelongsTo
    {
        return $this->belongsTo(ChannelMessage::class);
    }

    /**
     * @return array{operation: class-string<AiOperationType>, status: class-string<AiOperationStatus>}
     */
    protected function casts(): array
    {
        return [
            'operation' => AiOperationType::class,
            'status' => AiOperationStatus::class,
        ];
    }
}
