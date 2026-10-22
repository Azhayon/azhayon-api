<?php

namespace App\Http\Requests\Api;

use App\Enums\TagType;
use Illuminate\Validation\Rule;

class ListTagsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(TagType::class)],
            'sort' => ['nullable', Rule::in(['name', 'popular'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('azhayon.api.taxonomy_max_per_page')],
        ];
    }
}