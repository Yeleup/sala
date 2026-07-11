<?php

namespace App\Models;

use App\Enums\DereuCompanyStatus;
use Database\Factories\DereuCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Dereu company: exactly one WhatsApp number connected through Dereu.
 *
 * The project currently uses a single number, so at most one row exists —
 * the one whose external_id matches services.dereu.external_id.
 */
#[Fillable(['external_id', 'name', 'dereu_company_id', 'waba_id', 'phone_number_id', 'api_key', 'status', 'connected_at'])]
#[Hidden(['api_key'])]
class DereuCompany extends Model
{
    /** @use HasFactory<DereuCompanyFactory> */
    use HasFactory;

    /**
     * The company of this installation, keyed by the configured external id.
     */
    public static function current(): ?self
    {
        $externalId = config('services.dereu.external_id');

        if (blank($externalId)) {
            return null;
        }

        return static::query()->firstWhere('external_id', $externalId);
    }

    public function isConnected(): bool
    {
        return $this->status === DereuCompanyStatus::Connected;
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    /**
     * @return array{api_key: 'encrypted', status: class-string<DereuCompanyStatus>, connected_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'status' => DereuCompanyStatus::class,
            'connected_at' => 'datetime',
        ];
    }
}
