<?php

namespace App\Models;

use App\Enums\CustomerRequestStatus;
use Database\Factories\CustomerRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A customer's request for a specific listing. Equipment is never locked:
 * the same customer may send requests to several suppliers at once, and the
 * conflict of multiple acceptances is resolved by people over the phone.
 */
#[Fillable(['contact_id', 'listing_id', 'query_text', 'status'])]
class CustomerRequest extends Model
{
    /** @use HasFactory<CustomerRequestFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => CustomerRequestStatus::Pending->value,
    ];

    /** @return BelongsTo<Contact, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function accept(): void
    {
        $this->assertPending('accept');

        $this->update(['status' => CustomerRequestStatus::Accepted]);
    }

    public function decline(): void
    {
        $this->assertPending('decline');

        $this->update(['status' => CustomerRequestStatus::Declined]);
    }

    protected function assertPending(string $action): void
    {
        throw_unless(
            $this->status === CustomerRequestStatus::Pending,
            LogicException::class,
            sprintf('Cannot %s a request in the "%s" status.', $action, $this->status->value),
        );
    }

    /**
     * @return array{status: class-string<CustomerRequestStatus>}
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerRequestStatus::class,
        ];
    }
}
