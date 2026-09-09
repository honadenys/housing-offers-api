<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\PropertySearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class SearchPropertiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', 'string', 'size:3', 'alpha:ascii', 'uppercase'],
            'city' => ['nullable', 'string', 'max:100'],
            'check_in' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out' => ['required', 'date_format:Y-m-d', Rule::when(is_string($this->input('check_in')), 'after:check_in')],
            'guests' => ['required', 'integer', 'min:1', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function checkInDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('check_in'))
            ?? throw new LogicException('Validated check-in date could not be parsed.');
    }

    public function checkOutDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('check_out'))
            ?? throw new LogicException('Validated check-out date could not be parsed.');
    }

    public function filters(): PropertySearch
    {
        return new PropertySearch(
            checkIn: $this->checkInDate(),
            checkOut: $this->checkOutDate(),
            guests: (int) $this->validated('guests'),
            city: $this->validated('city'),
            perPage: (int) ($this->validated('per_page') ?? 15),
            page: (int) ($this->validated('page') ?? 1),
            currency: $this->validated('currency'),
        );
    }
}
