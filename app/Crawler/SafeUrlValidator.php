<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/**
 * SSRF guard. A URL is only fetchable when:
 *  - scheme is http/https, no embedded credentials, allowed port;
 *  - host is not a blocked name (localhost, *.internal, metadata endpoints, ...);
 *  - host is not an obfuscated numeric IP form;
 *  - EVERY resolved IP is public (no private, loopback, link-local, CGNAT,
 *    multicast, reserved, documentation, IPv4-mapped/NAT64 to private ranges).
 * The returned IP must be pinned for the connection (prevents DNS rebinding),
 * and every redirect target must be re-validated.
 */
final class SafeUrlValidator
{
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];

    private const BLOCKED_HOSTS = [
        'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', 'metadata', 'metadata.google.internal',
        'metadata.goog', 'instance-data', 'instance-data.ec2.internal', 'kubernetes.default', 'kubernetes.default.svc',
    ];

    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain', '.lan', '.home', '.corp', '.intranet', '.arpa', '.svc', '.cluster.local'];

    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public function __construct(private DnsResolver $resolver = new SystemDnsResolver())
    {
    }

    /**
     * @return array{ok:bool, reason:?string, scheme?:string, host?:string, port?:int, ip?:string}
     */
    public function validate(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            return self::fail('URL contains illegal characters or is too long.');
        }
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return self::fail('URL is not absolute.');
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return self::fail('Only HTTP and HTTPS URLs are allowed.');
        }
        if (isset($p['user']) || isset($p['pass']) || str_contains(explode('/', substr($url, strlen($scheme) + 3), 2)[0], '@')) {
            return self::fail('URLs with embedded credentials are not allowed.');
        }
        $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            return self::fail("Port $port is not allowed.");
        }
        $rawHost = strtolower(rtrim($p['host'], '.'));
        $host = Url::normalizeHost($rawHost);
        if ($host === null) {
            return self::fail('Invalid host name.');
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($literal)) {
                return self::fail('Host resolves to a private, loopback, link-local or reserved address.');
            }
            return ['ok' => true, 'reason' => null, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $literal];
        }
        // Obfuscated numeric hosts like 2130706433, 0x7f000001, 0177.0.0.1, 127.1
        $labels = explode('.', $host);
        $numericLabels = array_filter($labels, static fn(string $l) => (bool)preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $l));
        if (count($numericLabels) === count($labels) || preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', (string)end($labels))) {
            return self::fail('Numeric or obfuscated IP host forms are not allowed.');
        }
        if (!str_contains($host, '.')) {
            return self::fail('Single-label host names are not allowed.');
        }
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return self::fail('Host name is blocked.');
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::fail('Internal host names are not allowed.');
            }
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            return self::fail('DNS resolution failed.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return self::fail('Host resolves to a private, loopback, link-local or reserved address.');
            }
        }
        // Prefer IPv4 for maximum shared-hosting compatibility.
        $v4 = array_values(array_filter($ips, static fn($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        return ['ok' => true, 'reason' => null, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $v4[0] ?? $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return false;
            }
            // IPv4-mapped / compatible: validate the embedded IPv4 address as well.
            if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) {
                return false;
            }
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (strncmp($ipBin, $subBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
    }

    private static function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
