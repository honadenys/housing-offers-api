<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'supplier' => [
                'required',
                'string',
                'max:100',
                Rule::exists('suppliers', 'code'),
            ],
            'external_import_id' => ['required', 'string', 'max:191'],
            'sent_at' => ['required', 'date'],
            'offers' => ['required', 'array', 'list'],
            'offers.*' => ['required', 'array'],
            'offers.*.external_id' => ['required', 'string', 'max:191', 'distinct'],
            'offers.*.property' => ['required', 'array'],
            'offers.*.property.code' => ['required', 'string', 'max:100'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:100'],
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d'],
            'offers.*.max_guests' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'offers.*.price' => ['required', 'integer', 'min:0'],
            'offers.*.currency' => ['required', 'string', 'size:3', 'alpha:ascii', 'uppercase'],
            'offers.*.available_units' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes(
            'offers.*.check_out',
            'after:offers.*.check_in',
            fn (Fluent $input, mixed $offer): bool => is_string(data_get($offer, 'check_in')),
        );
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'offers.*.check_out.after' => 'The check_out date must be after check_in.',
        ];
    }
}
