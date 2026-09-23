<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CheckInMethod;
use App\Support\DevotionalClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'method' => ['nullable', Rule::enum(CheckInMethod::class)],

            // Not in the future. A passport that can be filled in ahead of
            // time is a wish list, and the plan is explicit that a visit is a
            // record of having been somewhere.
            // "Today" is the device's today: checked against the latest date
            // anywhere, since the server's UTC day lags India's by 5½ hours.
            'visited_on' => ['nullable', 'date', 'before_or_equal:'.DevotionalClock::latestDateAnywhere()],
            'visited_at' => ['nullable', 'date_format:H:i'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'note' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],

            // The scanned temple code, for a QR check-in. Its signature is
            // what makes the visit verified.
            'qr_code' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A GPS check-in without coordinates is a manual one claiming
            // otherwise, and the difference is the entire basis of the
            // stamp's credibility.
            $method = $this->input('method');
            $hasCoordinates = filled($this->input('latitude')) && filled($this->input('longitude'));

            if ($method === CheckInMethod::Gps->value && ! $hasCoordinates) {
                $validator->errors()->add('latitude', 'A GPS check-in needs your coordinates.');
            }

            // One without the other locates nothing.
            if (filled($this->input('latitude')) !== filled($this->input('longitude'))) {
                $validator->errors()->add('longitude', 'Send both latitude and longitude, or neither.');
            }
        });
    }
}
