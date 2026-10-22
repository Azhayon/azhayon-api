<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class ListSeriesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', Rule::in(['latest', 'title'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('azhayon.api.taxonomy_max_per_page')],
        ];
    }
}