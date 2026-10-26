<?php

namespace App\Http\Requests\Api;

class PreviewRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:100'],
        ];
    }
}