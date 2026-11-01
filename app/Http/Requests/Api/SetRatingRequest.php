<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;

class SetRatingRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'between:1,5'],
        ];
    }

    /** JSON whole numbers only: "4" and true would otherwise pass the integer rule. */
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($this->has('score') && ! is_int($this->input('score')) && ! $validator->errors()->has('score')) {
                $validator->errors()->add('score', 'The score must be a whole number from 1 to 5.');
            }
        });
    }
}