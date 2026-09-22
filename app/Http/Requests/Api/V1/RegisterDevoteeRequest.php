<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterDevoteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Either identifier is acceptable, but at least one is required;
            // that pair rule lives in withValidator below.
            'email' => ['nullable', 'email', 'max:255', 'unique:devotees,email'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/', 'unique:devotees,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Against the configured set rather than a hard-coded three:
            // adding Kannada should not mean remembering this line.
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locales::supported()))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (blank($this->input('email')) && blank($this->input('phone'))) {
                $validator->errors()->add('email', 'Provide an email address or a phone number.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a phone number in international format, e.g. +919876543210.',
        ];
    }
}
