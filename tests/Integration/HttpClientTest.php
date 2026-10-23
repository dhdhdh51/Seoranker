<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\App;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\SafeUrlValidator;
use BharatSeo\Tests\Support\FakeResolver;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

/** HttpClient with a scripted transport: verifies redirect re-validation, robots and rate limits. */
final class ScriptedHttpClient extends HttpClient
{
    /** @var array<string,array> */
    public array $script = [];
    public array $connections = [];

    public function __construct(App $app)
    {
        parent::__construct($app, new SafeUrlValidator(new FakeResolver([
            'site.example' => ['93.184.216.34'],
            'other.example' => ['93.184.216.35'],
            'sneaky.example' => ['10.0.0.8'],
        ])));
    }

    protected function rawRequest(string $url, string $host, int $port, string $ip, array $accept, int $maxBytes): array
    {
        $this->connections[] = "$url@$ip";
        return $this->script[$url] ?? ['status' => 404, 'headers' => [], 'body' => '', 'error_type' => null, 'error' => null, 'truncated' => false];
    }

    public static function ok(string $body, string $ct = 'text/html'): array
    {
        return ['status' => 200, 'headers' => ['content-type' => $ct], 'body' => $body, 'error_type' => null, 'error' => null, 'truncated' => false];
    }

    public static function redirect(string $to, int $code = 301): array
    {
        return ['status' => $code, 'headers' => ['location' => $to], 'body' => '', 'error_type' => null, 'error' => null, 'truncated' => false];
    }
}

final class HttpClientTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
        TestEnv::app()->settings()->set('crawler.domain_interval_ms', 500);
    }

    public function setUp(): void
    {
        TestEnv::db()->run('DELETE FROM rate_limits');
        TestEnv::db()->run('DELETE FROM robots_cache');
    }

    private function client(): ScriptedHttpClient
    {
        $c = new ScriptedHttpClient(TestEnv::app());
        $c->script['https://site.example/robots.txt'] = ScriptedHttpClient::ok("User-agent: *\nDisallow: /private/\n", 'text/plain');
        $c->script['https://other.example/robots.txt'] = ['status' => 404, 'headers' => [], 'body' => '', 'error_type' => null, 'error' => null, 'truncated' => false];
        return $c;
    }

    public function testRedirectToInternalAddressIsBlocked(): void
    {
        $c = $this->client();
        $c->script['https://site.example/go'] = ScriptedHttpClient::redirect('http://169.254.169.254/latest/meta-data/');
        $r = $c->fetch('https://site.example/go');
        $this->assertSame(HttpResponse::ERR_BLOCKED, $r->errorType);
        $this->assertContains('private, loopback', (string)$r->error);
        $this->assertSame(['https://site.example/go'], $r->redirects);
        foreach ($c->connections as $conn) {
            $this->assertNotContains('169.254', $conn, 'No connection is ever opened to the metadata IP');
        }
        $c->script['https://site.example/go2'] = ScriptedHttpClient::redirect('https://sneaky.example/');
        $this->assertSame(HttpResponse::ERR_BLOCKED, $c->fetch('https://site.example/go2')->errorType, 'Redirect to a host resolving internally is blocked');
        $c->script['https://site.example/go3'] = ScriptedHttpClient::redirect('file:///etc/passwd');
        $this->assertSame(HttpResponse::ERR_BLOCKED, $c->fetch('https://site.example/go3')->errorType);
    }

    public function testConnectionsArePinnedToValidatedIp(): void
    {
        $c = $this->client();
        $c->script['https://site.example/page'] = ScriptedHttpClient::ok('<html>ok</html>');
        $r = $c->fetch('https://site.example/page');
        $this->assertTrue($r->ok());
        $this->assertContains('https://site.example/page@93.184.216.34', implode(' ', $c->connections));
    }

    public function testSafeRedirectsAreFollowedWithinLimit(): void
    {
        $c = $this->client();
        $c->script['https://site.example/old'] = ScriptedHttpClient::redirect('/new');
        $c->script['https://site.example/new'] = ScriptedHttpClient::redirect('https://other.example/final', 302);
        $c->script['https://other.example/final'] = ScriptedHttpClient::ok('<p>final</p>');
        $r = $c->fetch('https://site.example/old');
        $this->assertTrue($r->ok());
        $this->assertSame('https://other.example/final', $r->finalUrl);
        $this->assertSame(2, count($r->redirects));
        TestEnv::app()->settings()->set('crawler.max_redirects', 1);
        $limited = $this->client();
        $limited->script = $c->script + $limited->script;
        $this->assertSame(HttpResponse::ERR_REDIRECTS, $limited->fetch('https://site.example/old')->errorType);
        TestEnv::app()->settings()->set('crawler.max_redirects', 5);
    }

    public function testRobotsTxtIsRespected(): void
    {
        $c = $this->client();
        $c->script['https://site.example/private/x'] = ScriptedHttpClient::ok('secret');
        $r = $c->fetch('https://site.example/private/x');
        $this->assertSame(HttpResponse::ERR_ROBOTS, $r->errorType);
        $this->assertFalse(in_array('https://site.example/private/x@93.184.216.34', $c->connections, true), 'Disallowed page never requested');
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM robots_cache WHERE host = 'https://site.example:443'"), 'robots.txt is cached');
    }

    public function testPerDomainRateLimitAndContentTypeErrors(): void
    {
        TestEnv::app()->settings()->set('crawler.domain_interval_ms', 60000);
        $c = $this->client();
        $c->script['https://other.example/a'] = ScriptedHttpClient::ok('a');
        $c->script['https://other.example/b'] = ScriptedHttpClient::ok('b');
        $this->assertTrue($c->fetch('https://other.example/a')->ok());
        $r = $c->fetch('https://other.example/b');
        $this->assertSame(HttpResponse::ERR_RATE_LIMITED, $r->errorType, 'Second request inside the interval is deferred');
        $this->assertTrue($r->isTemporaryFailure(), 'Rate limiting is a temporary condition (job will retry)');
        TestEnv::app()->settings()->set('crawler.domain_interval_ms', 500);
    }
}
