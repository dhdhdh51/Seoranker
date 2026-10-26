<?php
declare(strict_types=1);

namespace Flatmates\Tests\Integration;

use Flatmates\Core\ValidationException;
use Flatmates\Security\Auth;
use Flatmates\Services\OtpService;
use Flatmates\Tests\Support\TestEnv;
use Flatmates\Tests\TestCase;

final class AuthTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
    }

    private function auth(): Auth
    {
        return TestEnv::db() ? \Flatmates\Core\App::get()->make(Auth::class) : throw new \LogicException();
    }

    private function input(array $o = []): array
    {
        return $o + ['name' => 'Priya Sharma', 'email' => 'priya' . random_int(1000, 99999) . '@example.com', 'password' => 'Blue#Kettle42', 'password_confirmation' => 'Blue#Kettle42', 'date_of_birth' => '2005-03-04', 'age_confirmed' => '1', 'terms' => '1'];
    }

    public function testRegisterCreatesPendingUserAndEmailsOtpWithoutLoggingIt(): void
    {
        $in = $this->input();
        $id = $this->auth()->register($in);
        $u = TestEnv::db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame('pending', $u['status']);
        $this->assertTrue(password_verify('Blue#Kettle42', $u['password_hash']));
        $code = TestEnv::lastCode();
        $this->assertTrue($code !== null && strlen($code) === 6);
        $this->assertSame(TestEnv::mailbox()->last()->toEmail, $in['email']);
        $stored = TestEnv::db()->fetchColumn('SELECT code_hash FROM otp_codes WHERE user_id = ?', [$id]);
        $this->assertNotSame($code, $stored, 'OTP stored hashed');
        $audit = (string)json_encode(TestEnv::db()->fetchAll('SELECT * FROM audit_logs'));
        $this->assertNotContains($code, $audit);
        $this->assertNotContains('Blue#Kettle42', $audit);
        $this->auth()->verifyEmail($id, $code);
        $this->assertSame('active', TestEnv::db()->fetchColumn('SELECT status FROM users WHERE id = ?', [$id]));
    }

    public function testUnderageAndDuplicateRejected(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn() => $this->auth()->register($this->input(['date_of_birth' => (new \DateTimeImmutable('-17 years'))->format('Y-m-d')])));
        $this->assertTrue(isset($e->errors['date_of_birth']));
        $in = $this->input();
        $this->auth()->register($in);
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->register($in));
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->register($this->input(['terms' => null])));
    }

    public function testMinAgeIsConfigurable(): void
    {
        \Flatmates\Core\App::get()->settings()->set('rules.min_age', 21);
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->register($this->input(['date_of_birth' => (new \DateTimeImmutable('-20 years'))->format('Y-m-d')])));
        \Flatmates\Core\App::get()->settings()->set('rules.min_age', 18);
    }

    public function testOtpAttemptsExpiryAndCooldown(): void
    {
        $id = $this->auth()->register($this->input());
        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->assertThrows(ValidationException::class, fn() => $this->auth()->verifyEmail($id, '000000'));
        }
        $code = TestEnv::lastCode();
        $e = $this->assertThrows(ValidationException::class, fn() => $this->auth()->verifyEmail($id, (string)$code));
        $this->assertContains('Too many', $e->getMessage());
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->sendVerification($id), 'cooldown');
        TestEnv::db()->run('UPDATE otp_codes SET created_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE user_id = ?', [$id]);
        $code = $this->auth()->sendVerification($id);
        TestEnv::db()->run('UPDATE otp_codes SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = ? AND consumed_at IS NULL', [$id]);
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->verifyEmail($id, $code));
    }

    public function testLoginThrottleSuspensionAndGenericErrors(): void
    {
        $id = TestEnv::makeUser('login@example.com');
        $this->assertSame('invalid', $this->auth()->attempt('nobody@example.com', 'x', '10.0.0.1', false));
        for ($i = 0; $i < Auth::MAX_FAILURES; $i++) {
            $this->assertSame('invalid', $this->auth()->attempt('login@example.com', 'wrong', '10.0.0.2', false));
        }
        $this->assertSame('throttled', $this->auth()->attempt('login@example.com', 'Test#Passw0rd9', '10.0.0.2', false));
        TestEnv::db()->run('DELETE FROM login_attempts');
        $this->assertSame('ok', $this->auth()->attempt('LOGIN@example.com ', 'Test#Passw0rd9', '10.0.0.3', false));
        TestEnv::db()->run("UPDATE users SET status = 'suspended' WHERE id = ?", [$id]);
        $this->assertSame('suspended', $this->auth()->attempt('login@example.com', 'Test#Passw0rd9', '10.0.0.3', false));
        $this->assertSame('invalid', $this->auth()->attempt('login@example.com', 'bad', '10.0.0.3', false), 'no status leak with a wrong password');
    }

    public function testMaintenanceBlocksStudentsNotStaff(): void
    {
        TestEnv::makeUser('m-student@example.com');
        TestEnv::makeUser('m-admin@example.com', 'super_admin');
        \Flatmates\Core\App::get()->settings()->set('system.maintenance', true);
        $this->assertSame('maintenance', $this->auth()->attempt('m-student@example.com', 'Test#Passw0rd9', '10.0.0.9', false));
        $this->assertSame('ok', $this->auth()->attempt('m-admin@example.com', 'Test#Passw0rd9', '10.0.0.9', false));
        \Flatmates\Core\App::get()->settings()->set('system.maintenance', false);
    }

    public function testPasswordResetSilentForUnknownAndWorks(): void
    {
        $before = count(TestEnv::mailbox()->sent);
        $this->assertSame(null, $this->auth()->requestReset('ghost@example.com'));
        $this->assertSame($before, count(TestEnv::mailbox()->sent));
        TestEnv::makeUser('reset@example.com');
        $code = $this->auth()->requestReset('reset@example.com');
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->resetPassword('reset@example.com', (string)$code, 'short'));
        $this->auth()->resetPassword('reset@example.com', (string)$code, 'New#Kettle77');
        $this->assertSame('ok', $this->auth()->attempt('reset@example.com', 'New#Kettle77', '10.0.1.1', false));
        $this->assertThrows(ValidationException::class, fn() => $this->auth()->resetPassword('reset@example.com', (string)$code, 'Other#Kettle77'), 'code is single-use');
    }
}
