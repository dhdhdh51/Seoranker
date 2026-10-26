<?php
declare(strict_types=1);

namespace Flatmates\Tests\Unit;

use Flatmates\Http\Router;
use Flatmates\Security\Auth;
use Flatmates\Security\User;
use Flatmates\Services\SettingsService;
use Flatmates\Services\SsoReceiver;
use Flatmates\Tests\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    public function testPasswordRules(): void
    {
        $this->assertSame('Password must be at least 8 characters.', Auth::passwordProblem('a1'));
        $this->assertTrue(Auth::passwordProblem('onlyletters') !== null);
        $this->assertTrue(Auth::passwordProblem('rahul2024!', 'rahul@x.in') !== null, 'email name in password');
        $this->assertSame(null, Auth::passwordProblem('Blue#Kettle42', 'asha@x.in'));
    }

    public function testAge(): void
    {
        $this->assertSame(18, Auth::age((new \DateTimeImmutable('-18 years'))->format('Y-m-d')));
        $this->assertSame(17, Auth::age((new \DateTimeImmutable('-18 years +1 day'))->format('Y-m-d')));
        $this->assertSame(null, Auth::age('not-a-date'));
    }

    public function testRolePermissions(): void
    {
        $mod = new User(1, 'M', 'm@x', User::MODERATOR, 'active', ['reports']);
        $this->assertTrue($mod->can('reports'));
        $this->assertFalse($mod->can('verifications'));
        $this->assertTrue((new User(2, 'A', 'a@x', User::SUPER_ADMIN, 'active'))->can('verifications'));
        $this->assertFalse((new User(3, 'S', 's@x', User::STUDENT, 'active', ['reports']))->can('reports'), 'students never get permissions');
        $this->assertFalse((new User(4, 'R', 'r@x', User::STUDENT, 'restricted'))->canInteract());
        $this->assertSame(['reports'], User::fromRow(['id' => 1, 'name' => 'x', 'email' => 'x', 'role' => 'moderator', 'status' => 'active', 'permissions' => 'reports,hack'])->permissions);
    }

    public function testSettingsValidation(): void
    {
        $this->assertSame([true, 25, null], SettingsService::validate('rules.min_age', '25'));
        $this->assertFalse(SettingsService::validate('rules.min_age', '12')[0]);
        $this->assertFalse(SettingsService::validate('brand.primary_color', 'red')[0]);
        $this->assertFalse(SettingsService::validate('brand.name', "a\nb")[0]);
        $this->assertFalse(SettingsService::validate('nope', 'x')[0]);
    }

    public function testRouterParamsAndCasting(): void
    {
        $r = new Router();
        $r->get('/rooms/{slug}', ['X', 'y']);
        $r->get('/media/{dir}/{file}', ['X', 'z']);
        $r->post('/x/{id}', ['X', 'p']);
        $m = $r->match('GET', '/rooms/nice-room-12');
        $this->assertSame(['slug' => 'nice-room-12'], $m['args']);
        $this->assertSame(null, $r->match('GET', '/media/../config.php')['route']);
        $this->assertSame(null, $r->match('GET', '/media/private/' . str_repeat('a', 32) . '.jpg')['route'], 'private dir not routable');
        $this->assertSame(['id' => 7], $r->match('POST', '/x/7')['args']);
        $this->assertSame(['POST'], $r->match('GET', '/x/7')['allowed']);
    }

    public function testSsoTokenFormat(): void
    {
        $t = SsoReceiver::sign(['a' => 1], str_repeat('s', 40));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $t));
    }

    public function testEscaping(): void
    {
        $this->assertSame('&lt;script&gt;&quot;x&quot;&apos;', e('<script>"x"\''));
        $this->assertSame('₹1,25,000', inr(125000));
        $this->assertSame('₹999', inr(999));
    }
}
