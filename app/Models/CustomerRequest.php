<?php

namespace App\Models;

use App\Enums\CustomerRequestStatus;
use Database\Factories\CustomerRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A customer's request for a specific listing. Equipment is never locked:
 * the same customer may send requests to several suppliers at once, and the
 * conflict of multiple acceptances is resolved by people over the phone.
 */
#[Fillable(['contact_id', 'listing_id', 'query_text', 'status', 'supplier_contact_id'])]
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

    /**
     * Снимок поставщика, которому ушло уведомление, — владелец объявления
     * на момент размещения заявки. Текущий владелец живёт на объявлении
     * и может отличаться после передачи объявления другому контакту.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function notifiedSupplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_contact_id');
    }

    /**
     * Заявки, всё ещё ждущие ответа того, кто сейчас владеет объявлением.
     * Ожидание, адресованное прежнему владельцу (объявление передали) или
     * неизвестно кому (строки до появления снимка), не считается: такой
     * pending не блокирует повторную заявку и не показывается заказчику
     * как «ждём ответа». Единый предикат дедупа и бейджей каталога.
     *
     * @param  Builder<CustomerRequest>  $query
     * @return Builder<CustomerRequest>
     */
    public function scopeAwaitingCurrentOwner(Builder $query): Builder
    {
        return $query
            ->where('status', CustomerRequestStatus::Pending)
            ->whereHas('listing', fn (Builder $listing) => $listing->whereColumn('listings.contact_id', 'customer_requests.supplier_contact_id'));
    }

    public function accept(): void
    {
        $this->transition(CustomerRequestStatus::Accepted, 'accept');
    }

    public function decline(): void
    {
        $this->transition(CustomerRequestStatus::Declined, 'decline');
    }

    /**
     * Закрывает ожидание без решения поставщика: таймаут ответа,
     * недоставленное уведомление или ручное снятие оператором.
     */
    public function expire(): void
    {
        $this->transition(CustomerRequestStatus::Expired, 'expire');
    }

    /**
     * Единственный переход из Pending — условным UPDATE по базе, а не по
     * снимку в памяти: ответ поставщика, таймаут и оператор живут в
     * разных процессах, и гвард check-then-act пропустил бы обоих.
     * Проигравший переход получает LogicException — тот же контракт, что
     * и раньше, поэтому гонка кончается честной веткой «уже решена», а
     * не перезаписью решения.
     */
    protected function transition(CustomerRequestStatus $to, string $action): void
    {
        $won = static::query()
            ->whereKey($this->getKey())
            ->where('status', CustomerRequestStatus::Pending)
            ->update(['status' => $to]) === 1;

        $this->refresh();

        throw_unless(
            $won,
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
