<?php

namespace App\Http\Requests\Api;

/** For endpoints that take no query parameters: any parameter at all is refused. */
class NoParametersRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}