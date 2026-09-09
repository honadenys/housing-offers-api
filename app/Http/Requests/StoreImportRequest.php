<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $inputOffers = $this->input('offers', []);
        $offers = is_array($inputOffers)
            ? array_map(
                static function (mixed $offer): mixed {
                    if (! is_array($offer)) {
                        return $offer;
                    }

                    if (isset($offer['currency']) && is_string($offer['currency'])) {
                        $offer['currency'] = strtoupper((string) $offer['currency']);
                    }

                    return $offer;
                },
                $inputOffers,
            )
            : $inputOffers;

        $this->merge([
            'supplier' => is_string($this->input('supplier'))
                ? trim($this->input('supplier'))
                : $this->input('supplier'),
            'offers' => $offers,
        ]);
    }

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
            'offers.*.currency' => ['required', 'string', 'size:3', 'alpha'],
            'offers.*.available_units' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $offers = $this->input('offers', []);

                if (! is_array($offers)) {
                    return;
                }

                foreach ($offers as $index => $offer) {
                    if (! is_array($offer)) {
                        continue;
                    }

                    if (
                        ! isset($offer['check_in'], $offer['check_out'])
                        || $validator->errors()->has("offers.{$index}.check_in")
                        || $validator->errors()->has("offers.{$index}.check_out")
                    ) {
                        continue;
                    }

                    $checkIn = date_create_from_format('!Y-m-d', (string) $offer['check_in']);
                    $checkOut = date_create_from_format('!Y-m-d', (string) $offer['check_out']);

                    if ($checkIn !== false && $checkOut !== false && $checkOut <= $checkIn) {
                        $validator->errors()->add(
                            "offers.{$index}.check_out",
                            'The check_out date must be after check_in.',
                        );
                    }
                }
            },
        ];
    }
}
