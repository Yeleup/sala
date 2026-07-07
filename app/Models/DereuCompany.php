<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DereuCompany extends Model
{
    /** @use HasFactory<\Database\Factories\DereuCompanyFactory> */
    use HasFactory;

    public const string STATUS_PROVISIONED = 'provisioned';

    public const string STATUS_CONNECTED = 'connected';

    public const string STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'external_id',
        'name',
        'dereu_company_id',
        'api_key',
        'waba_id',
        'phone_number_id',
        'display_phone_number',
        'status',
    ];

    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array{api_key: 'encrypted'}
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }
}
