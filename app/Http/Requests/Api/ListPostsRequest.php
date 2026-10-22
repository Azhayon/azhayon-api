<?php

namespace App\Http\Requests\Api;

use App\Enums\PostType;
use Illuminate\Validation\Rule;

class ListPostsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', 'exists:categories,slug'],
            'tag' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', 'exists:tags,slug'],
            'series' => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9-]+$/', 'exists:series,slug'],
            'type' => ['nullable', Rule::enum(PostType::class)],
            'sort' => ['nullable', Rule::in(['newest', 'popular', 'rating', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('azhayon.api.max_per_page')],
        ];
    }
}