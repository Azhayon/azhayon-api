<?php

namespace App\Http\Requests\Api;

class ConfirmNewsletterRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:20', 'max:100'],
        ];
    }
}