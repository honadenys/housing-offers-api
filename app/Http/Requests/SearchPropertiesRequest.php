<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SearchPropertiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('city'))) {
            $this->merge(['city' => trim($this->input('city'))]);
        }
    }

    public function rules(): array
    {
        return [
            'city' => ['nullable', 'string', 'max:100'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d'],
            'guests' => ['required', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $checkIn = date_create_from_format('!Y-m-d', (string) $this->input('check_in'));
                $checkOut = date_create_from_format('!Y-m-d', (string) $this->input('check_out'));

                if ($checkIn !== false && $checkOut !== false && $checkOut <= $checkIn) {
                    $validator->errors()->add(
                        'check_out',
                        'The check_out date must be after check_in.',
                    );
                }
            },
        ];
    }
}
