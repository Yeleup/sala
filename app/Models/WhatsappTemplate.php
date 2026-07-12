<?php

namespace App\Models;

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use Database\Factories\WhatsappTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp Template Message of the project's number. The template itself
 * lives in Meta (created via Dereu or Meta Business Manager and synced
 * back); this row mirrors its content and moderation status. Only approved
 * templates are usable outside the 24-hour session window.
 */
#[Fillable(['name', 'language', 'category', 'status', 'rejection_reason', 'body', 'components', 'dereu_template_id'])]
class WhatsappTemplate extends Model
{
    /** @use HasFactory<WhatsappTemplateFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => WhatsappTemplateStatus::Pending->value,
    ];

    public function isApproved(): bool
    {
        return $this->status === WhatsappTemplateStatus::Approved;
    }

    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', WhatsappTemplateStatus::Approved);
    }

    /**
     * @return array{category: class-string<WhatsappTemplateCategory>, status: class-string<WhatsappTemplateStatus>, components: 'array'}
     */
    protected function casts(): array
    {
        return [
            'category' => WhatsappTemplateCategory::class,
            'status' => WhatsappTemplateStatus::class,
            'components' => 'array',
        ];
    }
}
