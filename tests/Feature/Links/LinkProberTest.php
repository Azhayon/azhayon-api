<?php

namespace Tests\Feature\Links;

use App\Support\Links\LinkProber;
use App\Support\Links\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkProberTest extends TestCase
{
    private function probe(string $url): array
    {
        return (new LinkProber(new UrlGuard))->probe($url);
    }

    private function makeResult(string $outcome, ?int $status = null, ?string $finalUrl = null, ?string $note = null): array
    {
        return ['outcome' => $outcome, 'http_status' => $status, 'final_url' => $finalUrl, 'note' => $note];
    }

    public function test_a_working_link_is_ok(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertSame($this->makeResult('ok', 200), $this->probe('https://93.184.216.34/page'));
    }

    public function test_a_missing_page_fails(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertSame($this->makeResult('failed', 404), $this->probe('https://93.184.216.34/gone'));
    }

    public function test_sites_that_reject_head_requests_are_retried_with_get(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 405)
            : Http::response('ok', 200));

        $this->assertSame($this->makeResult('ok', 200), $this->probe('https://93.184.216.34/page'));
        Http::assertSentCount(2);
    }

    public function test_permanent_redirects_are_reported_as_moved(): void
    {
        Http::fake([
            'https://93.184.216.34/old' => Http::response('', 301, ['Location' => '/new']),
            'https://93.184.216.34/new' => Http::response('', 200),
        ]);

        $this->assertSame(
            $this->makeResult('moved', 200, 'https://93.184.216.34/new'),
            $this->probe('https://93.184.216.34/old'),
        );
    }

    public function test_trivial_redirects_are_not_reported(): void
    {
        Http::fake([
            'http://93.184.216.34/x' => Http::response('', 301, ['Location' => 'https://93.184.216.34/x/']),
            'https://93.184.216.34/x/' => Http::response('', 200),
        ]);

        $this->assertSame(
            $this->makeResult('ok', 200, 'https://93.184.216.34/x/'),
            $this->probe('http://93.184.216.34/x'),
        );
    }

    public function test_temporary_redirects_are_followed_without_complaint(): void
    {
        Http::fake([
            'https://93.184.216.34/short' => Http::response('', 302, ['Location' => '/somewhere/else']),
            'https://93.184.216.34/somewhere/else' => Http::response('', 200),
        ]);

        $this->assertSame(
            $this->makeResult('ok', 200, 'https://93.184.216.34/somewhere/else'),
            $this->probe('https://93.184.216.34/short'),
        );
    }

    public function test_redirects_into_private_networks_are_blocked(): void
    {
        Http::fake([
            'https://93.184.216.34/start' => Http::response('', 301, ['Location' => 'http://127.0.0.1/admin']),
        ]);

        $this->assertSame(
            $this->makeResult('unverifiable', null, null, 'blocked'),
            $this->probe('https://93.184.216.34/start'),
        );
        Http::assertSentCount(1); // the internal address was never requested
    }

    public function test_redirect_loops_give_up(): void
    {
        Http::fake([
            'https://93.184.216.34/a' => Http::response('', 301, ['Location' => '/b']),
            'https://93.184.216.34/b' => Http::response('', 301, ['Location' => '/a']),
        ]);

        $this->assertSame(
            $this->makeResult('failed', null, null, 'too-many-redirects'),
            $this->probe('https://93.184.216.34/a'),
        );
        Http::assertSentCount(4);
    }

    public function test_sites_that_block_bots_are_unverifiable(): void
    {
        $status = 403;

        // One stub for the whole test: it reads the current value on every request.
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });

        foreach ([403, 429, 401] as $status) {
            $this->assertSame(
                $this->makeResult('unverifiable', $status, null, 'blocked-by-site'),
                $this->probe('https://93.184.216.34/profile'),
                (string) $status,
            );
        }
    }

    public function test_nonstandard_status_codes_are_unverifiable_not_broken(): void
    {
        Http::fake(fn () => throw new \InvalidArgumentException('Status code must be an integer value between 1xx and 5xx.'));

        $this->assertSame(
            $this->makeResult('unverifiable', null, null, 'nonstandard-status'),
            $this->probe('https://93.184.216.34/profile'),
        );
    }

    public function test_unexpected_errors_never_stop_a_run_or_count_as_broken(): void
    {
        Http::fake(fn () => throw new \RuntimeException('something odd'));

        $this->assertSame(
            $this->makeResult('unverifiable', null, null, 'error'),
            $this->probe('https://93.184.216.34/page'),
        );
    }

    public function test_server_errors_fail(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertSame($this->makeResult('failed', 503), $this->probe('https://93.184.216.34/page'));
    }

    public function test_timeouts_fail_with_a_note(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 8000 milliseconds'));

        $this->assertSame(
            $this->makeResult('failed', null, null, 'timeout'),
            $this->probe('https://93.184.216.34/page'),
        );
    }
}