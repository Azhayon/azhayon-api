<?php

namespace Tests\Unit;

use App\Support\Links\UnsafeUrlException;
use App\Support\Links\UrlGuard;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public function test_unsafe_urls_are_blocked(): void
    {
        $guard = new UrlGuard;

        $urls = [
            '',
            'javascript:alert(1)',
            'ftp://93.184.216.34/',
            'file:///etc/passwd',
            'http://127.0.0.1/',
            'http://127.1.2.3/admin',
            'http://0.0.0.0/',
            'http://10.1.2.3/',
            'http://172.16.0.1/',
            'http://192.168.0.5/x',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/',
            'http://198.18.0.1/',
            'http://[::1]/',
            'http://localhost/',
            'https://user:secret@93.184.216.34/',
            'http://93.184.216.34:22/',
            'http://93.184.216.34:6379/',
        ];

        foreach ($urls as $url) {
            try {
                $guard->resolve($url);
                $this->fail("A dangerous URL was allowed: {$url}");
            } catch (UnsafeUrlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_public_addresses_are_allowed(): void
    {
        $guard = new UrlGuard;

        $this->assertSame(
            ['host' => '93.184.216.34', 'port' => 80, 'ip' => '93.184.216.34'],
            $guard->resolve('http://93.184.216.34/page'),
        );

        $this->assertSame(
            ['host' => '8.8.8.8', 'port' => 8443, 'ip' => '8.8.8.8'],
            $guard->resolve('https://8.8.8.8:8443/x'),
        );
    }
}