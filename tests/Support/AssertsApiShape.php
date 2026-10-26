<?php

namespace Tests\Support;

use App\Support\Api\Contract;

trait AssertsApiShape
{
    protected function assertApiShape(string $definition, mixed $data, string $label = ''): void
    {
        $errors = Contract::validate($definition, $data);

        $this->assertSame(
            [],
            $errors,
            trim("{$label} does not match the contract \"{$definition}\":")."\n".implode("\n", $errors),
        );
    }
}