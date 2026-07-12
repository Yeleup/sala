<?php

namespace App\Http\Requests;

use App\Enums\ListingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving a listing from the supplier web form. Submitting to moderation
 * requires every business field to be filled — the web form is the place
 * where a supplier completes what the AI could not collect in chat.
 */
class UpdateSupplierListingRequest extends FormRequest
{
    /**
     * Authorization is the signed URL (see the `signed` middleware on the
     * supplier portal routes), not a logged-in user.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ListingType::class)],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'location' => ['required', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».',
            'string' => 'Поле «:attribute» должно быть текстом.',
            'max' => 'Поле «:attribute» слишком длинное (не более :max символов).',
            'enum' => 'Выберите тип: техника или услуга.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'тип',
            'category' => 'категория',
            'description' => 'описание',
            'location' => 'локация',
            'price' => 'цена/тариф',
        ];
    }
}
