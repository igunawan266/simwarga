<?php

namespace App\Libraries\Tools;

use App\Exceptions\DomainRuleException;

/**
 * SSRF protection for tenant-defined HTTP tools.
 *
 * - https only (plain http and private targets only when explicitly allowed outside production,
 *   for local mocks)
 * - the host must resolve, and every resolved address must be public: loopback, private,
 *   link-local (incl. 169.254.169.254 cloud metadata), CGNAT and reserved ranges are refused
 * - the caller pins the vetted IP with CURLOPT_RESOLVE so DNS cannot be swapped afterwards
 */
class UrlGuard
{
    public const ALLOWED_PORTS = [443, 8443, 80, 8080];

    public function __construct(private readonly bool $allowPrivate = false)
    {
    }

    /** Validate the shape of a URL template when a tool is saved. */
    public function assertValidTemplate(string $url): void
    {
        // The authority part (scheme://user@host:port) must be literal: placeholders are only allowed in path/query.
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*\{#i', $url)) {
            throw new DomainRuleException('Host URL tidak boleh berisi parameter.');
        }
        $probe = preg_replace('/\{[a-z0-9_]+\}/i', 'x', $url) ?? $url;
        $parts = parse_url($probe);
        if (! $parts || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)) {
            throw new DomainRuleException('URL tidak valid. Gunakan format https://domain/path.');
        }
        if (($parts['scheme'] ?? '') !== 'https' && ! $this->allowPrivate) {
            throw new DomainRuleException('Hanya URL https yang diizinkan.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new DomainRuleException('Kredensial di dalam URL tidak diizinkan; gunakan header rahasia.');
        }
    }

    /**
     * @return array{host: string, port: int, ip: string}
     */
    public function resolve(string $url): array
    {
        $this->assertValidTemplate($url);
        $parts  = parse_url($url);
        $scheme = $parts['scheme'];
        $host   = strtolower(trim($parts['host'], '[]'));
        $port   = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! $this->allowPrivate && ! in_array($port, self::ALLOWED_PORTS, true)) { // dev override also lifts the port allow-list
            throw new DomainRuleException("Port {$port} tidak diizinkan.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if ($ips === []) {
            throw new DomainRuleException("Host {$host} tidak dapat di-resolve.");
        }
        foreach ($ips as $ip) {
            if (! $this->allowPrivate && ! $this->isPublic($ip)) {
                throw new DomainRuleException('Alamat tujuan tidak diizinkan (jaringan internal/privat).');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Ranges PHP's flags do not cover.
        foreach (['100.64.0.0/10', '0.0.0.0/8', '192.0.0.0/24', '198.18.0.0/15'] as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                return false;
            }
        }

        return ! str_starts_with(strtolower($ip), 'fe80:') && ! str_starts_with(strtolower($ip), 'fc') && ! str_starts_with(strtolower($ip), 'fd');
    }

    private function lookup(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        return array_values(array_unique(array_filter($ips ?: (gethostbynamel($host) ?: []))));
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        [$subnet, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
