<?php

namespace App\Http\Requests\Api;

use App\Support\Api\CommentText;
use Illuminate\Contracts\Validation\Validator;

class StoreCommentRequest extends ApiRequest
{
    public const MAX_LINKS = 2;

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => CommentText::clean($body)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'is_spoiler' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $body = $this->input('body');

            if (is_string($body) && CommentText::linkCount($body) > self::MAX_LINKS) {
                $validator->errors()->add('body', 'Please keep links to '.self::MAX_LINKS.' or fewer.');
            }
        });
    }
}