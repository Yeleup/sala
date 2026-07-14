<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The supplier changing their display name from the web portal. The name
 * lives on the contact, so it shows up on every listing of that supplier;
 * an empty value reverts to the WhatsApp profile name.
 */
class UpdateSupplierNameRequest extends FormRequest
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
            'display_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('display_name'));

        $this->merge(['display_name' => $name === '' ? null : $name]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.string' => 'Имя должно быть текстом.',
            'display_name.max' => 'Имя слишком длинное (не более :max символов).',
        ];
    }
}
