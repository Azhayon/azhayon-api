<?php

namespace App\Rules;

use App\Support\Content\ContentNormalizer;
use App\Support\Content\InvalidContentException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPostContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(ContentNormalizer::class)->normalize($value);
        } catch (InvalidContentException $e) {
            foreach ($e->messages() as $message) {
                $fail($message);
            }
        }
    }
}