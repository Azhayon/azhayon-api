<?php

namespace App\Http\Requests\Api;

class ListSitemapPostsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}