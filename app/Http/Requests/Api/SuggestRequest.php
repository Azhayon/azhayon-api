<?php

namespace App\Http\Requests\Api;

use App\Support\Api\SearchQuery;

class SuggestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:80', SearchQuery::rule()],
        ];
    }
}