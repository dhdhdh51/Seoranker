<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\HttpException;
use BharatSeo\Security\Gate;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Services\DiscoveryService;
use BharatSeo\Services\ProjectService;
use BharatSeo\Services\ProspectService;
use BharatSeo\Services\ReportService;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

/** Role-based permissions and cross-client isolation at the service/query layer. */
final class AuthorizationTest extends TestCase
{
    private static array $ids;
    private static int $prospectB;
    private static int $backlinkB;

    public static function setUpBeforeClass(): void
    {
        $app = TestEnv::freshApp();
        self::$ids = TestEnv::seed();
        $pB = TestEnv::project(self::$ids['projectB']);
        self::$prospectB = (int)$app->make(ProspectService::class)->createFromUrl($pB, 'https://gadgets.example/reviews/', ['category' => 'resource_page'], null, null)['id'];
        $pA = TestEnv::project(self::$ids['projectA']);
        $app->make(ProspectService::class)->createFromUrl($pA, 'https://farmers.example/resources/', ['category' => 'resource_page'], null, null);
        self::$backlinkB = (int)$app->make(BacklinkService::class)->createRecord($pB, 'https://gadgets.example/reviews/', 'https://techshop.example/', null, 'manual', null, null)['id'];
    }

    private function gate(): Gate
    {
        $g = TestEnv::app()->make(Gate::class);
        $g->flush();
        return $g;
    }

    public function testSuperAdminSeesEverything(): void
    {
        $admin = TestEnv::user(self::$ids['admin']);
        $this->assertSame(null, $this->gate()->projectIds($admin));
        $this->assertTrue($this->gate()->can($admin, Gate::MANAGE, self::$ids['projectB']));
        $this->assertFalse($this->gate()->can($admin, Gate::APPROVE_CLIENT, self::$ids['projectA']), 'Admins never approve on behalf of the client');
    }

    public function testManagerOnlyAssignedProjects(): void
    {
        $m = TestEnv::user(self::$ids['manager']);
        $this->assertSame([self::$ids['projectA']], $this->gate()->projectIds($m));
        $this->assertTrue($this->gate()->can($m, Gate::MANAGE, self::$ids['projectA']));
        $this->assertFalse($this->gate()->can($m, Gate::VIEW, self::$ids['projectB']));
        $e = $this->assertThrows(HttpException::class, fn() => TestEnv::app()->make(ProjectService::class)->find($m, self::$ids['projectB']));
        $this->assertSame(404, $e->status, 'Foreign projects are reported as not found (no existence leak)');
    }

    public function testViewerMemberCannotManage(): void
    {
        $v = TestEnv::user(self::$ids['viewer']);
        $this->assertTrue($this->gate()->can($v, Gate::VIEW, self::$ids['projectA']));
        $e = $this->assertThrows(HttpException::class, fn() => TestEnv::app()->make(ProjectService::class)->find($v, self::$ids['projectA'], Gate::MANAGE));
        $this->assertSame(403, $e->status);
        $this->assertThrows(HttpException::class, fn() => TestEnv::app()->make(DiscoveryService::class)->addManual($v, self::$ids['projectA'], ['urls' => 'https://x.example/']));
    }

    public function testClientIsolationAcrossServices(): void
    {
        $a = TestEnv::user(self::$ids['clientA_user']);
        $app = TestEnv::app();
        $this->assertSame([self::$ids['projectA']], $this->gate()->projectIds($a));
        // Direct id manipulation must fail everywhere.
        $this->assertSame(404, $this->assertThrows(HttpException::class, fn() => $app->make(ProspectService::class)->find($a, self::$prospectB))->status);
        $this->assertSame(404, $this->assertThrows(HttpException::class, fn() => $app->make(BacklinkService::class)->find($a, self::$backlinkB))->status);
        $this->assertSame(404, $this->assertThrows(HttpException::class, fn() => $app->make(ReportService::class)->projectReport($a, self::$ids['projectB']))->status);
        // Lists only include own data even when filtering for the foreign project id.
        $list = $app->make(ProspectService::class)->list($a, ['project_id' => self::$ids['projectB']]);
        $this->assertSame(0, $list['pager']->total);
        $all = $app->make(ProspectService::class)->list($a, []);
        $this->assertSame(1, $all['pager']->total);
        foreach ($all['rows'] as $r) {
            $this->assertSame(self::$ids['projectA'], (int)$r['project_id']);
        }
        // Clients cannot manage, even their own project.
        $this->assertFalse($this->gate()->can($a, Gate::MANAGE, self::$ids['projectA']));
        $this->assertTrue($this->gate()->can($a, Gate::APPROVE_CLIENT, self::$ids['projectA']));
    }

    public function testClientExportPermission(): void
    {
        $b = TestEnv::user(self::$ids['clientB_user']);
        $app = TestEnv::app();
        $this->assertTrue($this->gate()->can($b, Gate::VIEW, self::$ids['projectB']));
        $this->assertFalse($this->gate()->can($b, Gate::EXPORT, self::$ids['projectB']), 'Client B has no export permission');
        $out = fopen('php://memory', 'w+');
        $this->assertSame(403, $this->assertThrows(HttpException::class, fn() => $app->make(ReportService::class)->export($b, 'prospects', ['project_id' => self::$ids['projectB']], $out))->status);
        $this->assertSame(403, $this->assertThrows(HttpException::class, fn() => $app->make(ReportService::class)->export($b, 'prospects', [], $out))->status);
        $a = TestEnv::user(self::$ids['clientA_user']);
        $rows = $app->make(ReportService::class)->export($a, 'prospects', [], $out);
        $this->assertSame(1, $rows, 'Client A exports only its own prospect');
    }

    public function testArchivedProjectHiddenFromClient(): void
    {
        TestEnv::db()->update('projects', ['status' => 'archived'], 'id = ?', [self::$ids['projectB']]);
        $b = TestEnv::user(self::$ids['clientB_user']);
        $this->assertSame([], $this->gate()->projectIds($b));
        TestEnv::db()->update('projects', ['status' => 'active'], 'id = ?', [self::$ids['projectB']]);
    }

    public function testDisabledClientAccountLosesAccess(): void
    {
        TestEnv::db()->update('clients', ['status' => 'inactive'], 'id = ?', [self::$ids['clientA']]);
        $this->assertSame([], $this->gate()->projectIds(TestEnv::user(self::$ids['clientA_user'])));
        TestEnv::db()->update('clients', ['status' => 'active'], 'id = ?', [self::$ids['clientA']]);
    }
}
