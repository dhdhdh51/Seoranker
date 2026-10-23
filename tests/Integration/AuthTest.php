<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Security\Auth;
use BharatSeo\Tests\Support\FakeTransport;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;
use BharatSeo\Services\MailerService;

final class AuthTest extends TestCase
{
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
        self::$ids = TestEnv::seed();
    }

    private function auth(): Auth
    {
        $app = TestEnv::app();
        $app->forget(Auth::class);
        return $app->make(Auth::class);
    }

    public function testLoginSuccessAndFailure(): void
    {
        $this->assertSame('ok', $this->auth()->attempt('manager@agency.test', 'Test#Passw0rd9', '10.0.0.1', false));
        $this->assertSame('invalid', $this->auth()->attempt('manager@agency.test', 'wrong-password', '10.0.0.1', false));
        $this->assertSame('invalid', $this->auth()->attempt('nobody@agency.test', 'whatever123!', '10.0.0.1', false));
        $this->assertSame('ok', $this->auth()->attempt('MANAGER@agency.test ', 'Test#Passw0rd9', '10.0.0.1', false), 'Email is case/space-insensitive');
        $row = TestEnv::db()->fetch('SELECT last_login_at FROM users WHERE id = ?', [self::$ids['manager']]);
        $this->assertTrue($row['last_login_at'] !== null);
    }

    public function testDisabledAccountCannotSignIn(): void
    {
        TestEnv::db()->update('users', ['status' => 'disabled'], 'id = ?', [self::$ids['viewer']]);
        $this->assertSame('disabled', $this->auth()->attempt('viewer@agency.test', 'Test#Passw0rd9', '10.0.0.2', false));
        TestEnv::db()->update('users', ['status' => 'active'], 'id = ?', [self::$ids['viewer']]);
    }

    public function testLoginThrottlingAfterRepeatedFailures(): void
    {
        for ($i = 0; $i < Auth::MAX_EMAIL_FAILURES; $i++) {
            $this->assertSame('invalid', $this->auth()->attempt('owner@client-b.test', 'bad-guess-' . $i, '10.0.0.3', false));
        }
        $this->assertSame('throttled', $this->auth()->attempt('owner@client-b.test', 'Test#Passw0rd9', '10.0.0.3', false), 'Correct password is refused while throttled');
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM audit_logs WHERE action = 'auth.login_throttled'"));
        TestEnv::db()->run('DELETE FROM login_attempts');
        $this->assertSame('ok', $this->auth()->attempt('owner@client-b.test', 'Test#Passw0rd9', '10.0.0.3', false));
    }

    public function testPasswordResetIsSingleUseAndExpires(): void
    {
        $fake = new FakeTransport();
        TestEnv::app()->settings()->set('smtp.from_email', 'noreply@agency.test');
        TestEnv::app()->make(MailerService::class)->setTransport($fake);
        $token = $this->auth()->requestPasswordReset('owner@client-a.test', '10.0.0.4');
        $this->assertTrue(is_string($token) && strlen($token) === 64);
        $this->assertSame(1, count($fake->sent), 'Reset email sent');
        $this->assertContains($token, $fake->sent[0]->body);
        $stored = TestEnv::db()->fetchColumn('SELECT token_hash FROM password_resets ORDER BY id DESC LIMIT 1');
        $this->assertSame(hash('sha256', $token), $stored, 'Only the token hash is stored');
        $this->assertSame(null, $this->auth()->requestPasswordReset('unknown@nowhere.test', '10.0.0.4'), 'Unknown emails create nothing');

        $this->assertTrue($this->auth()->completePasswordReset($token, 'short', '10.0.0.4') !== [], 'Weak password rejected');
        $this->assertSame([], $this->auth()->completePasswordReset($token, 'Brand-New-Pass7', '10.0.0.4'));
        $this->assertTrue($this->auth()->completePasswordReset($token, 'Another-Pass8x', '10.0.0.4') !== [], 'Token is single-use');
        $this->assertSame('ok', $this->auth()->attempt('owner@client-a.test', 'Brand-New-Pass7', '10.0.0.4', false));

        $t2 = $this->auth()->requestPasswordReset('owner@client-a.test', '10.0.0.4', false);
        TestEnv::db()->run('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $this->assertSame(null, $this->auth()->findValidReset($t2), 'Expired token rejected');
        TestEnv::app()->make(MailerService::class)->setTransport(null);
    }
}
