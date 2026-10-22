<?php

namespace App\Http\Requests\Api;

use App\Enums\PostType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', 'exists:categories,slug'],
            'tag' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', 'exists:tags,slug'],
            'series' => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9-]+$/', 'exists:series,slug'],
            'type' => ['nullable', Rule::enum(PostType::class)],
            'sort' => ['nullable', Rule::in(['newest', 'popular', 'rating'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('azhayon.api.max_per_page')],
        ];
    }

    /** Unknown parameters are refused, so cache-busting URLs and typos fail loudly. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), array_keys($this->rules())) as $unknown) {
                $validator->errors()->add((string) $unknown, 'Unknown parameter.');
            }
        });
    }
}