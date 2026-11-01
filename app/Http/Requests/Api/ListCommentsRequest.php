<?php

namespace App\Http\Requests\Api;

class ListCommentsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('azhayon.api.comments_max_per_page')],
        ];
    }
}