<?php

namespace Tests\Feature\Api;

use App\Support\Http\ClientIdentity;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientIdentityTest extends TestCase
{
    private function key(): string
    {
        return str_repeat('k', 40);
    }

    private function makeRequest(array $headers = []): Request
    {
        $request = Request::create('/api/v1/site', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_the_frontend_is_trusted_only_with_the_exact_key(): void
    {
        config(['azhayon.api.frontend_key' => $this->key()]);

        $this->assertTrue(ClientIdentity::isTrustedFrontend($this->makeRequest(['X-Frontend-Key' => $this->key()])));
        $this->assertFalse(ClientIdentity::isTrustedFrontend($this->makeRequest(['X-Frontend-Key' => str_repeat('x', 40)])));
        $this->assertFalse(ClientIdentity::isTrustedFrontend($this->makeRequest()));

        // An unset or too-short key can never match, not even an empty header.
        foreach ([null, '', 'short'] as $configured) {
            config(['azhayon.api.frontend_key' => $configured]);

            $this->assertFalse(ClientIdentity::isTrustedFrontend($this->makeRequest(['X-Frontend-Key' => (string) $configured])));
        }
    }

    public function test_forwarded_ips_are_used_only_when_trusted_and_valid(): void
    {
        config(['azhayon.api.frontend_key' => $this->key()]);

        $trusted = fn (string $forwarded) => ClientIdentity::ip($this->makeRequest([
            'X-Frontend-Key' => $this->key(),
            'X-Client-IP' => $forwarded,
        ]));

        $this->assertSame('198.51.100.7', $trusted('198.51.100.7'));
        $this->assertSame('2001:db8::1', $trusted(' 2001:db8::1 '));
        $this->assertSame('203.0.113.9', $trusted('not-an-ip'));
        $this->assertSame('203.0.113.9', $trusted(''));

        $untrusted = $this->makeRequest(['X-Client-IP' => '198.51.100.7']);
        $this->assertSame('203.0.113.9', ClientIdentity::ip($untrusted));

        $wrongKey = $this->makeRequest(['X-Frontend-Key' => str_repeat('x', 40), 'X-Client-IP' => '198.51.100.7']);
        $this->assertSame('203.0.113.9', ClientIdentity::ip($wrongKey));
    }
}