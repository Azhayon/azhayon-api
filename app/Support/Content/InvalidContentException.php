<?php

namespace App\Support\Content;

use RuntimeException;

class InvalidContentException extends RuntimeException
{
    /** @param  list<string>  $messages */
    public function __construct(private readonly array $messages)
    {
        parent::__construct(implode(' ', $messages));
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}