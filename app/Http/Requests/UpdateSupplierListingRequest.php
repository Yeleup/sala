<?php

namespace App\Http\Requests;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Support\WhatsappText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * Services carry no brand: a brand left over from the equipment state
     * of the form is dropped silently — the field is optional, so unlike
     * the category it must never block the submission.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('type') === ListingType::Service->value) {
            $this->merge(['brand_id' => null]);
        }

        // The title ends up in WhatsApp template parameters, which Meta
        // rejects over newlines and space runs — normalize before storing.
        if (is_string($this->input('title'))) {
            $this->merge(['title' => WhatsappText::templateParameter($this->input('title')) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ListingType::class)],
            'title' => ['required', 'string', 'max:255'],
            'category_id' => [
                'required',
                'integer',
                // A listing's category must carry the listing's type.
                Rule::exists('categories', 'id')->where('type', (string) $this->input('type')),
            ],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'description' => ['required', 'string', 'max:2000'],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'location_detail' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:255'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ListingMedia::MAX_PHOTO_KILOBYTES],
            'remove_photos' => ['nullable', 'array'],
            'remove_photos.*' => ['integer'],
        ];
    }

    /**
     * The photo cap counts what stays after the marked removals plus the
     * new uploads — not the uploads alone.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['photos', 'photos.*', 'remove_photos', 'remove_photos.*'])) {
                    return;
                }

                /** @var Listing $listing */
                $listing = $this->route('listing');

                $keptPhotos = $listing->photos()
                    ->whereNotIn('id', $this->input('remove_photos', []))
                    ->count();

                if ($keptPhotos + count($this->file('photos', [])) > Listing::MAX_PHOTOS) {
                    $validator->errors()->add(
                        'photos',
                        'У объявления может быть не более '.Listing::MAX_PHOTOS.' фотографий.',
                    );
                }
            },
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
            'brand_id.integer' => 'Выберите марку из списка.',
            'brand_id.exists' => 'Выберите марку из списка.',
            'location_id.integer' => 'Выберите локацию из подсказок.',
            'location_id.exists' => 'Выберите локацию из подсказок.',
            'photos.*.image' => 'Файл «:attribute» не является изображением.',
            'photos.*.mimes' => 'Фото принимаются в форматах JPG, PNG или WebP.',
            'photos.*.max' => 'Фото слишком большое — не более 5 МБ.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'тип',
            'title' => 'название',
            'category_id' => 'категория',
            'brand_id' => 'марка',
            'description' => 'описание',
            'location_id' => 'локация',
            'location_detail' => 'уточнение адреса',
            'price' => 'цена/тариф',
            'photos.*' => 'фото',
        ];
    }
}
