<?php

namespace App\Http\Requests\Api;

use App\Support\Api\SearchQuery;

class SearchRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:80', SearchQuery::rule()],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('azhayon.api.max_per_page')],
        ];
    }
}