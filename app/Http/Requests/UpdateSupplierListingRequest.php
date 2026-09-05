<?php

namespace App\Http\Requests;

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\RepairPlace;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Support\WhatsappText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Saving a listing from the supplier web form. Submitting to moderation
 * requires every business field to be filled — the web form is the place
 * where a supplier completes what the AI could not collect in chat. The
 * questionnaire branches on the listing's kind: a driver's form has
 * nothing in common with a rental's beyond the title and the location.
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
     * The title ends up in WhatsApp template parameters, which Meta rejects
     * over newlines and space runs — normalize before storing.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => WhatsappText::templateParameter($this->input('title')) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->rulesFor($this->kind());
    }

    /**
     * The questionnaire of the given kind. Public and parameterized on
     * purpose: the lock test in ListingLifecycleTest calls it per kind to
     * keep the form's required scalars equal to the publication gate's.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rulesFor(ListingKind $kind): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'location_detail' => ['nullable', 'string', 'max:255'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ListingMedia::MAX_PHOTO_KILOBYTES],
            'remove_photos' => ['nullable', 'array'],
            'remove_photos.*' => ['integer'],
            ...match ($kind) {
                ListingKind::Rental => [
                    'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
                    'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
                    'description' => ['required', 'string', 'max:2000'],
                    'price' => ['required', 'string', 'max:255'],
                ],
                ListingKind::Repair => [
                    'person_name' => ['required', 'string', 'max:255'],
                    'services' => ['required', 'string', 'max:2000'],
                    'repair_place' => ['required', Rule::enum(RepairPlace::class)],
                    'description' => ['nullable', 'string', 'max:2000'],
                    'price' => ['nullable', 'string', 'max:255'],
                ],
                ListingKind::Driver => [
                    'person_name' => ['required', 'string', 'max:255'],
                    'licence_type' => ['required', Rule::enum(LicenceType::class)],
                    'experience_years' => ['required', 'integer', 'min:0', 'max:80'],
                    'travels_to_other_cities' => ['required', 'boolean'],
                    // The machinery is required in one of two forms: ticked
                    // from the dictionary, or named in the driver's own words
                    // when the dictionary has no entry for it — the operator
                    // then adds the category during moderation. Demanding a
                    // checkbox here would lock out a driver of a bus.
                    'machine_categories' => ['required_without:unlisted_machinery', 'array'],
                    'machine_categories.*' => ['integer', Rule::exists('categories', 'id')],
                    'unlisted_machinery' => ['nullable', 'string', 'max:120'],
                    'description' => ['nullable', 'string', 'max:2000'],
                    // Required only while the listing has no stored document —
                    // decided in after(), where the DB is the source of truth.
                    'document' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ListingMedia::MAX_PHOTO_KILOBYTES],
                ],
            },
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validatePhotoCap($validator),
            fn (Validator $validator) => $this->validateDocumentPresence($validator),
        ];
    }

    /**
     * The photo cap counts what stays after the marked removals plus the
     * new uploads — not the uploads alone.
     */
    private function validatePhotoCap(Validator $validator): void
    {
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
    }

    /**
     * A driver's licence photo is required until the listing carries one:
     * the stored media rows are checked rather than a form flag, so a
     * tampered request cannot talk its way past the document.
     */
    private function validateDocumentPresence(Validator $validator): void
    {
        if (! $this->kind()->requiresDocument()) {
            return;
        }

        if ($this->hasFile('document') || $validator->errors()->has('document')) {
            return;
        }

        /** @var Listing $listing */
        $listing = $this->route('listing');

        if ($listing->documents()->doesntExist()) {
            $validator->errors()->add('document', 'Прикрепите фото удостоверения — без него объявление не будет опубликовано.');
        }
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
            'enum' => 'Выберите вариант из списка.',
            'category_id.integer' => 'Выберите категорию из списка.',
            'category_id.exists' => 'Выберите категорию из списка.',
            'brand_id.integer' => 'Выберите марку из списка.',
            'brand_id.exists' => 'Выберите марку из списка.',
            'location_id.integer' => 'Выберите локацию из подсказок.',
            'location_id.exists' => 'Выберите локацию из подсказок.',
            'experience_years.integer' => 'Стаж указывается числом лет.',
            'experience_years.min' => 'Стаж не может быть отрицательным.',
            'experience_years.max' => 'Стаж больше :max лет не принимается.',
            'travels_to_other_cities.boolean' => 'Отметка «готов выезжать» повреждена — обновите страницу и попробуйте снова.',
            'machine_categories.required_without' => 'Отметьте технику из списка или напишите её словами.',
            'machine_categories.*.integer' => 'Выберите технику из списка.',
            'machine_categories.*.exists' => 'Выберите технику из списка.',
            'document.image' => 'Документ принимается как фото: JPG, PNG или WebP.',
            'document.mimes' => 'Документ принимается как фото: JPG, PNG или WebP.',
            'document.max' => 'Фото документа слишком большое — не более '.(ListingMedia::MAX_PHOTO_KILOBYTES / 1024).' МБ.',
            'photos.*.image' => 'Файл «:attribute» не является изображением.',
            'photos.*.mimes' => 'Фото принимаются в форматах JPG, PNG или WebP.',
            'photos.*.max' => 'Фото слишком большое — не более '.(ListingMedia::MAX_PHOTO_KILOBYTES / 1024).' МБ.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'название',
            'category_id' => 'категория',
            'brand_id' => 'марка',
            'description' => 'описание',
            'location_id' => 'локация',
            'location_detail' => 'уточнение адреса',
            'price' => 'цена/тариф',
            'person_name' => $this->kind() === ListingKind::Repair ? 'имя или название сервиса' : 'имя',
            'services' => 'услуги',
            'repair_place' => 'где выполняете ремонт',
            'licence_type' => 'тип удостоверения',
            'experience_years' => 'стаж',
            'travels_to_other_cities' => 'готовность выезжать',
            'machine_categories' => 'техника',
            'unlisted_machinery' => 'техника словами',
            'document' => 'фото удостоверения',
            'photos.*' => 'фото',
        ];
    }

    /**
     * The kind driving the questionnaire. The route model is absent only
     * outside HTTP (the lock test) — rental keeps the pre-kinds behavior.
     */
    private function kind(): ListingKind
    {
        return $this->route('listing')?->kind ?? ListingKind::Rental;
    }
}
