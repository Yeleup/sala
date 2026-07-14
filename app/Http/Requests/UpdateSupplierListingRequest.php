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
            'category_id' => [
                'required',
                'integer',
                // A listing's category must carry the listing's type.
                Rule::exists('categories', 'id')->where('type', (string) $this->input('type')),
            ],
            'description' => ['required', 'string', 'max:2000'],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'location_detail' => ['nullable', 'string', 'max:255'],
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
            'category_id.integer' => 'Выберите категорию из списка.',
            'category_id.exists' => 'Категория не соответствует выбранному типу — выберите категорию из списка нужного типа.',
            'location_id.integer' => 'Выберите локацию из подсказок.',
            'location_id.exists' => 'Выберите локацию из подсказок.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'тип',
            'category_id' => 'категория',
            'description' => 'описание',
            'location_id' => 'локация',
            'location_detail' => 'уточнение адреса',
            'price' => 'цена/тариф',
        ];
    }
}
