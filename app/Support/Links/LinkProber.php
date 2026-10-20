<?php

namespace App\Support\Links;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a URL's status, following a few redirects by hand so that every hop
 * passes through the UrlGuard.
 */
final class LinkProber
{
    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * @return array{outcome: string, http_status: ?int, final_url: ?string, note: ?string}
     *                                                                                      outcome is one of: ok, moved, failed, unverifiable
     */
    public function probe(string $url): array
    {
        $current = $url;
        $permanent = true;
        $redirected = false;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $target = $this->guard->resolve($current);
            } catch (UnsafeUrlException $e) {
                return $e->reason === 'dns'
                    ? $this->result('failed', null, null, 'dns')
                    : $this->result('unverifiable', null, null, 'blocked');
            }

            try {
                $response = $this->request($current, $target);
            } catch (ConnectionException $e) {
                return $this->result('failed', null, null, $this->connectionNote($e));
            } catch (\InvalidArgumentException) {
                // Guzzle cannot represent status codes outside 100-599 (some sites send 999 to bots).
                return $this->result('unverifiable', null, null, 'nonstandard-status');
            } catch (\Throwable $e) {
                // One strange response must neither stop the whole run nor raise a false alarm.
                Log::warning('Link check error', ['exception' => $e::class]);

                return $this->result('unverifiable', null, null, 'error');
            }

            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                if ($redirected && $permanent && $this->canonical($current) !== $this->canonical($url)) {
                    return $this->result('moved', $status, $current, null);
                }

                return $this->result('ok', $status, $redirected ? $current : null, null);
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $next = $this->absolute($current, (string) $response->header('Location'));

                if ($next === null) {
                    return $this->result('failed', $status, null, 'bad-redirect');
                }

                $permanent = $permanent && in_array($status, [301, 308], true);
                $redirected = true;
                $current = $next;

                continue;
            }

            if (in_array($status, [401, 403, 429, 999], true)) {
                return $this->result('unverifiable', $status, null, 'blocked-by-site');
            }

            return $this->result('failed', $status, null, null);
        }

        return $this->result('failed', null, null, 'too-many-redirects');
    }

    /** @param  array{host: string, port: int, ip: string}  $target */
    private function request(string $url, array $target): Response
    {
        $send = fn (string $method): Response => Http::withHeaders([
            'User-Agent' => (string) config('azhayon.link_checker.user_agent'),
            'Accept' => '*/*',
        ])
            ->timeout(8)
            ->connectTimeout(4)
            ->withOptions([
                'allow_redirects' => false,
                'stream' => $method === 'GET', // read the status, never download the body
                // Connect to the address we checked, whatever DNS says later.
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]],
            ])
            ->send($method, $url);

        $response = $send('HEAD');

        // Some sites refuse HEAD requests but answer GET normally.
        return in_array($response->status(), [400, 403, 405, 501], true) ? $send('GET') : $response;
    }

    private function connectionNote(ConnectionException $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'timed out') => 'timeout',
            str_contains($message, 'ssl') || str_contains($message, 'certificate') => 'tls',
            default => 'connection',
        };
    }

    /** Host without "www.", path without a trailing slash, no scheme: used to ignore cosmetic redirects. */
    private function canonical(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return strtolower((string) preg_replace('/^www\./i', '', $parts['host'] ?? ''))
            .rtrim($parts['path'] ?? '', '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function absolute(string $base, string $location): ?string
    {
        $location = trim($location);

        if ($location === '' || preg_match('/[\x00-\x20]/', $location)) {
            return null;
        }

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname(($parts['path'] ?? '/').'x'), '/').'/'.$location;
    }

    /** @return array{outcome: string, http_status: ?int, final_url: ?string, note: ?string} */
    private function result(string $outcome, ?int $status, ?string $finalUrl, ?string $note): array
    {
        return ['outcome' => $outcome, 'http_status' => $status, 'final_url' => $finalUrl, 'note' => $note];
    }
}