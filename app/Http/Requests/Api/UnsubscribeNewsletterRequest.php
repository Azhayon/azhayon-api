<?php

namespace App\Http\Requests\Api;

class UnsubscribeNewsletterRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:40'],
        ];
    }
}