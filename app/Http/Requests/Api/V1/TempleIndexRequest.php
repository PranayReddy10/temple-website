<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TempleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'deity' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],

            // Proximity search. Latitude and longitude are meaningless alone,
            // so each requires the other rather than silently being ignored.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:2000'],

            'verified' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:name,-name,recent,distance'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'lat.required_with' => 'Both lat and lng are required for a nearby search.',
            'lng.required_with' => 'Both lat and lng are required for a nearby search.',
        ];
    }

    public function hasCoordinates(): bool
    {
        return $this->filled('lat') && $this->filled('lng');
    }

    public function radiusKm(): float
    {
        return (float) ($this->input('radius') ?? 50);
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
