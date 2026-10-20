<?php

namespace App\Support\Links;

/**
 * Decides whether the link checker may fetch a URL. Anything that could reach
 * the server's own network is refused (SSRF protection).
 */
final class UrlGuard
{
    private const PORTS = [80, 443, 8080, 8443];

    /**
     * @return array{host: string, port: int, ip: string}
     *
     * @throws UnsafeUrlException
     */
    public function resolve(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '') {
            throw new UnsafeUrlException('scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('credentials');
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, self::PORTS, true)) {
            throw new UnsafeUrlException('port');
        }

        $host = strtolower($parts['host']);

        if (str_starts_with($host, '[')) {
            throw new UnsafeUrlException('blocked'); // IPv6 addresses are not checked
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new UnsafeUrlException('dns');
        }

        // Every address must be public; one internal address is enough to refuse.
        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                throw new UnsafeUrlException('blocked');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    private function isPublic(string $ip): bool
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        $long = ip2long($ip);

        // Ranges PHP's flags do not cover: carrier-grade NAT, special-purpose and benchmarking.
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$network, $bits]) {
            $mask = -1 << (32 - $bits);

            if (($long & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }
}