<?php

namespace App\Http\Requests\Api;

class SubscribeNewsletterRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => trim($email)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
        ];
    }
}