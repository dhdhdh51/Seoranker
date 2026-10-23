<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\PageFetcher;
use BharatSeo\Crawler\Url;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Support\Paginator;
use BharatSeo\Support\Validator;

final class ProjectService
{
    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    private function fetcher(): PageFetcher
    {
        return $this->app->make(HttpClient::class);
    }

    /** @return array{rows:array, pager:Paginator} */
    public function list(User $user, array $filters = [], int $page = 1): array
    {
        [$scopeSql, $params] = $this->gate->scope($user, 'p.id');
        $where = 'WHERE 1=1' . $scopeSql;
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'paused', 'archived'], true)) {
            $where .= ' AND p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where .= ' AND p.client_id = ?';
            $params[] = (int)$filters['client_id'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (p.name LIKE ? OR p.domain LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$filters['q']) . '%';
            array_push($params, $like, $like);
        }
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM projects p $where", $params);
        $pager = new Paginator($total, $page, 25);
        $rows = $this->db->fetchAll(
            "SELECT p.*, c.name AS client_name,
                (SELECT COUNT(*) FROM prospect_pages pp WHERE pp.project_id = p.id) AS prospect_count,
                (SELECT COUNT(*) FROM backlink_records b WHERE b.project_id = p.id AND b.status IN ('active','changed_anchor','nofollow','redirected')) AS active_links
             FROM projects p JOIN clients c ON c.id = p.client_id $where ORDER BY p.status = 'active' DESC, p.name" . $pager->limitSql(),
            $params
        );
        return ['rows' => $rows, 'pager' => $pager];
    }

    /** @return array<int,string> id => "Client · Project" for selects */
    public function options(User $user): array
    {
        [$scopeSql, $params] = $this->gate->scope($user, 'p.id');
        $rows = $this->db->fetchAll("SELECT p.id, p.name, c.name AS client_name FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.status <> 'archived'$scopeSql ORDER BY c.name, p.name", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r['client_name'] . ' · ' . $r['name'];
        }
        return $out;
    }

    public function find(User $user, int $id, string $ability = Gate::VIEW): array
    {
        $this->gate->authorizeProject($user, $id, $ability);
        $p = $this->db->fetch('SELECT p.*, c.name AS client_name FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?', [$id]);
        if (!$p) {
            throw new HttpException(404);
        }
        return $p;
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    /** Clients the user may create projects for. */
    public function creatableClients(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return $this->db->fetchPairs("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name");
        }
        if ($user->isManager()) {
            $ids = $this->gate->clientIds($user) ?? [];
            if (!$ids) {
                return [];
            }
            return $this->db->fetchPairs("SELECT id, name FROM clients WHERE status = 'active' AND id IN (" . Database::in($ids) . ') ORDER BY name', $ids);
        }
        return [];
    }

    private function validate(array $input): array
    {
        $v = Validator::make($input)
            ->string('name', 'Project name', true, 160, 2)
            ->url('website_url', 'Website URL')
            ->text('business_description', 'Business description', false, 5000)
            ->string('niche', 'Niche', false, 160)
            ->string('target_countries', 'Target countries', false, 500)
            ->string('target_languages', 'Target languages', false, 255)
            ->string('brand_name', 'Brand name', false, 160)
            ->text('preferred_anchors', 'Preferred anchor phrases', false, 5000)
            ->email('contact_email', 'Contact email', false)
            ->string('outreach_from_name', 'Outreach sender name', false, 160)
            ->text('outreach_signature', 'Outreach signature', false, 2000)
            ->text('exclusions', 'Exclusions', false, 20000)
            ->text('keywords', 'Keywords', false, 20000)
            ->text('topics', 'Topics', false, 20000)
            ->text('target_pages', 'Target landing pages', false, 50000)
            ->in('approval_policy', 'Approval policy', ['manager', 'client', 'manager_and_client'], true, 'manager');
        $data = $v->validated();
        $data['domain'] = Url::domain((string)$data['website_url']) ?? '';
        if ($data['domain'] === '' || !str_contains($data['domain'], '.')) {
            throw ValidationException::single('website_url', 'Website URL must include a valid domain.');
        }
        // Target pages must belong to the project domain.
        $pages = [];
        foreach (Validator::lines($data['target_pages'] ?? '', 500) as $line) {
            $n = Url::normalize($line);
            if ($n === null || !Url::belongsToDomain($n, $data['domain'])) {
                throw ValidationException::single('target_pages', 'Each target landing page must be a URL on ' . $data['domain'] . ': ' . mb_substr($line, 0, 80));
            }
            $pages[$n] = $n;
        }
        $data['target_pages_list'] = array_values($pages);
        return $data;
    }

    public function create(User $user, array $input): int
    {
        $clientId = (int)($input['client_id'] ?? 0);
        if (!array_key_exists($clientId, $this->creatableClients($user))) {
            throw ValidationException::single('client_id', 'Choose a client you are allowed to manage.');
        }
        $data = $this->validate($input);
        try {
            $id = $this->db->transaction(function (Database $db) use ($user, $clientId, $data): int {
                $id = $db->insert('projects', [
                    'client_id' => $clientId,
                    'name' => $data['name'],
                    'website_url' => $data['website_url'],
                    'domain' => $data['domain'],
                    'business_description' => $data['business_description'],
                    'niche' => $data['niche'],
                    'target_countries' => $data['target_countries'],
                    'target_languages' => $data['target_languages'],
                    'brand_name' => $data['brand_name'],
                    'preferred_anchors' => $data['preferred_anchors'],
                    'contact_email' => $data['contact_email'],
                    'outreach_from_name' => $data['outreach_from_name'],
                    'outreach_signature' => $data['outreach_signature'],
                    'exclusions' => $data['exclusions'],
                    'approval_policy' => $data['approval_policy'],
                    'verification_token' => bin2hex(random_bytes(16)),
                    'created_by' => $user->id,
                ]);
                $this->syncKeywords($id, $data['keywords'] ?? '', $data['topics'] ?? '');
                $this->syncTargetPages($id, $data['target_pages_list']);
                if ($user->isManager()) {
                    $db->insert('project_members', ['project_id' => $id, 'user_id' => $user->id, 'role' => 'manager']);
                }
                return $id;
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::single('website_url', 'This client already has a project for ' . $data['domain'] . '.');
            }
            throw $e;
        }
        $this->gate->flush();
        $this->app->make(AuditLogger::class)->log('project.created', $user->id, $id, ['name' => $data['name'], 'domain' => $data['domain']], 'project', null, $id);
        return $id;
    }

    public function update(User $user, int $id, array $input): void
    {
        $project = $this->find($user, $id, Gate::MANAGE);
        $data = $this->validate($input);
        $domainChanged = $data['domain'] !== $project['domain'];
        try {
            $this->db->transaction(function (Database $db) use ($id, $data, $domainChanged): void {
                $update = [
                    'name' => $data['name'],
                    'website_url' => $data['website_url'],
                    'domain' => $data['domain'],
                    'business_description' => $data['business_description'],
                    'niche' => $data['niche'],
                    'target_countries' => $data['target_countries'],
                    'target_languages' => $data['target_languages'],
                    'brand_name' => $data['brand_name'],
                    'preferred_anchors' => $data['preferred_anchors'],
                    'contact_email' => $data['contact_email'],
                    'outreach_from_name' => $data['outreach_from_name'],
                    'outreach_signature' => $data['outreach_signature'],
                    'exclusions' => $data['exclusions'],
                    'approval_policy' => $data['approval_policy'],
                ];
                if ($domainChanged) {
                    // Ownership must be re-verified for a new domain.
                    $update['verified_at'] = null;
                    $update['verification_method'] = null;
                    $update['verification_token'] = bin2hex(random_bytes(16));
                }
                $db->update('projects', $update, 'id = ?', [$id]);
                $this->syncKeywords($id, $data['keywords'] ?? '', $data['topics'] ?? '');
                $this->syncTargetPages($id, $data['target_pages_list']);
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::single('website_url', 'This client already has a project for ' . $data['domain'] . '.');
            }
            throw $e;
        }
        $this->app->make(AuditLogger::class)->log('project.updated', $user->id, $id, ['domain_changed' => $domainChanged], 'project', null, $id);
    }

    public function setStatus(User $user, int $id, string $status): void
    {
        $this->find($user, $id, Gate::MANAGE);
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            throw new HttpException(422);
        }
        $this->db->update('projects', ['status' => $status], 'id = ?', [$id]);
        if ($status !== 'active') {
            $this->app->make(QueueService::class)->cancelPending($id);
        }
        $this->app->make(AuditLogger::class)->log('project.status_changed', $user->id, $id, ['status' => $status], 'project', null, $id);
    }

    private function syncKeywords(int $projectId, string $keywords, string $topics): void
    {
        $this->db->run('DELETE FROM project_keywords WHERE project_id = ?', [$projectId]);
        foreach (['keyword' => $keywords, 'topic' => $topics] as $type => $text) {
            foreach (Validator::lines($text, 300, 190, true) as $kw) {
                $this->db->run('INSERT IGNORE INTO project_keywords (project_id, keyword, type) VALUES (?, ?, ?)', [$projectId, mb_strtolower($kw), $type]);
            }
        }
    }

    /** Keep existing target pages that are still listed (so prospects keep their reference). */
    private function syncTargetPages(int $projectId, array $urls): void
    {
        $hashes = [];
        foreach ($urls as $u) {
            $h = hash('sha256', $u);
            $hashes[] = $h;
            $this->db->run('INSERT IGNORE INTO target_pages (project_id, url, url_hash) VALUES (?, ?, ?)', [$projectId, $u, $h]);
        }
        if ($hashes) {
            $this->db->run('DELETE FROM target_pages WHERE project_id = ? AND url_hash NOT IN (' . Database::in($hashes) . ')', array_merge([$projectId], $hashes));
        } else {
            $this->db->run('DELETE FROM target_pages WHERE project_id = ?', [$projectId]);
        }
    }

    public function keywords(int $projectId, ?string $type = null): array
    {
        $sql = 'SELECT keyword FROM project_keywords WHERE project_id = ?';
        $params = [$projectId];
        if ($type) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        return array_column($this->db->fetchAll($sql . ' ORDER BY keyword', $params), 'keyword');
    }

    public function targetPages(int $projectId): array
    {
        return $this->db->fetchAll('SELECT * FROM target_pages WHERE project_id = ? ORDER BY priority, id', [$projectId]);
    }

    public function members(int $projectId): array
    {
        return $this->db->fetchAll('SELECT pm.*, u.name, u.email, u.role AS user_role FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? ORDER BY u.name', [$projectId]);
    }

    public function addMember(User $actor, int $projectId, int $userId, string $role): void
    {
        $this->gate->requireRole($actor, User::SUPER_ADMIN);
        $u = $this->db->fetch("SELECT id, role FROM users WHERE id = ? AND role = 'seo_manager'", [$userId]);
        if (!$u || !in_array($role, ['manager', 'viewer'], true)) {
            throw ValidationException::single('user_id', 'Select an active SEO manager.');
        }
        $this->db->run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$projectId, $userId, $role]);
        $this->gate->flush();
        $this->app->make(AuditLogger::class)->log('project.member_added', $actor->id, $projectId, ['user_id' => $userId, 'role' => $role], 'project', null, $projectId);
    }

    public function removeMember(User $actor, int $projectId, int $userId): void
    {
        $this->gate->requireRole($actor, User::SUPER_ADMIN);
        $this->db->run('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
        $this->gate->flush();
        $this->app->make(AuditLogger::class)->log('project.member_removed', $actor->id, $projectId, ['user_id' => $userId], 'project', null, $projectId);
    }

    /** Exclusion list (domains) for a project plus the global blocklist. */
    public function exclusions(array $project): array
    {
        $list = [];
        $text = ($project['exclusions'] ?? '') . "\n" . (string)$this->app->settings()->get('discovery.blocklist', '');
        foreach (Validator::lines($text, 5000, 253, true) as $line) {
            $d = Url::domain(str_contains($line, '://') ? $line : 'https://' . ltrim($line, '*.'));
            if ($d) {
                $list[$d] = true;
            }
        }
        return array_keys($list);
    }

    // ---------------------------------------------------------------
    // Ownership verification
    // ---------------------------------------------------------------

    public function verificationFileName(array $project): string
    {
        return 'bharatseo-verification-' . substr($project['verification_token'], 0, 12) . '.txt';
    }

    public function verificationInstructions(array $project): array
    {
        $origin = rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $project['website_url']) ?? '', '/');
        return [
            'file' => [
                'url' => $origin . '/' . $this->verificationFileName($project),
                'content' => 'bharatseo-verification=' . $project['verification_token'],
            ],
            'meta' => '<meta name="bharatseo-verification" content="' . $project['verification_token'] . '">',
            'dns' => ['host' => $project['domain'], 'type' => 'TXT', 'value' => 'bharatseo-verification=' . $project['verification_token']],
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function verifyOwnership(User $user, int $id, string $method, ?PageFetcher $fetcher = null, ?callable $dnsLookup = null): array
    {
        $project = $this->find($user, $id, Gate::MANAGE);
        $ins = $this->verificationInstructions($project);
        $token = $project['verification_token'];
        $fetcher ??= $this->fetcher();
        $ok = false;
        $detail = '';
        switch ($method) {
            case 'file':
                // The owner explicitly asked us to fetch this one file, so robots rules for it are not applied.
                $r = $fetcher->fetch($ins['file']['url'], ['respect_robots' => false, 'accept' => ['text/plain', 'text/html', ''], 'max_bytes' => 10000]);
                $ok = $r->ok() && str_contains($r->body, $ins['file']['content']);
                $detail = $r->errorType ? (string)$r->error : 'HTTP ' . $r->status;
                break;
            case 'meta':
                $r = $fetcher->fetch($project['website_url'], ['respect_robots' => false]);
                if ($r->ok()) {
                    $ok = (bool)preg_match('/<meta[^>]+name=["\']bharatseo-verification["\'][^>]+content=["\']' . preg_quote($token, '/') . '["\']/i', $r->body)
                        || (bool)preg_match('/<meta[^>]+content=["\']' . preg_quote($token, '/') . '["\'][^>]+name=["\']bharatseo-verification["\']/i', $r->body);
                }
                $detail = $r->errorType ? (string)$r->error : 'HTTP ' . $r->status;
                break;
            case 'dns':
                $lookup = $dnsLookup ?? static fn(string $host): array => @dns_get_record($host, DNS_TXT) ?: [];
                foreach ($lookup($project['domain']) as $rec) {
                    $txt = $rec['txt'] ?? implode('', $rec['entries'] ?? []);
                    if (trim((string)$txt) === $ins['dns']['value']) {
                        $ok = true;
                    }
                }
                $detail = $ok ? 'TXT record found' : 'TXT record not found (DNS changes can take time to propagate)';
                break;
            default:
                throw new HttpException(422);
        }
        if ($ok) {
            $this->db->update('projects', ['verified_at' => gmdate('Y-m-d H:i:s'), 'verification_method' => $method], 'id = ?', [$id]);
        }
        $this->app->make(AuditLogger::class)->log($ok ? 'project.ownership_verified' : 'project.ownership_verification_failed', $user->id, $id, ['method' => $method, 'detail' => $detail], 'project', null, $id);
        return ['ok' => $ok, 'message' => $ok ? 'Ownership verified via ' . $method . '.' : 'Verification failed: ' . $detail];
    }

    public static function isVerified(array $project): bool
    {
        return !empty($project['verified_at']);
    }

    /**
     * Import target pages from the project's own sitemap. Requires verified ownership.
     * @return int number of pages added
     */
    public function importSitemap(User $user, int $id, ?PageFetcher $fetcher = null): int
    {
        $project = $this->find($user, $id, Gate::MANAGE);
        if (!self::isVerified($project)) {
            throw ValidationException::single('sitemap', 'Verify ownership of ' . $project['domain'] . ' before crawling the site.');
        }
        $fetcher ??= $this->fetcher();
        $origin = rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $project['website_url']) ?? '', '/');
        $r = $fetcher->fetch($origin . '/sitemap.xml', ['accept' => ['application/xml', 'text/xml', 'application/x-xml', 'text/plain', '']]);
        if (!$r->ok()) {
            throw ValidationException::single('sitemap', 'Could not fetch sitemap.xml: ' . ($r->error ?? 'HTTP ' . $r->status));
        }
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r->body, $m);
        $added = 0;
        foreach (array_slice($m[1] ?? [], 0, 200) as $loc) {
            $n = Url::normalize(html_entity_decode($loc));
            if ($n && Url::belongsToDomain($n, $project['domain']) && !preg_match('/\.xml(\?|$)/i', $n)) {
                $added += $this->db->run('INSERT IGNORE INTO target_pages (project_id, url, url_hash, priority) VALUES (?, ?, ?, 9)', [$id, $n, hash('sha256', $n)])->rowCount();
            }
        }
        $this->app->make(AuditLogger::class)->log('project.sitemap_imported', $user->id, $id, ['added' => $added], 'project', null, $id);
        return $added;
    }

    /** Summary counts for the project overview. */
    public function overview(int $id): array
    {
        $p = fn(string $sql) => (int)$this->db->fetchColumn($sql, [$id]);
        return [
            'prospects' => $p('SELECT COUNT(*) FROM prospect_pages WHERE project_id = ?'),
            'needs_review' => $p("SELECT COUNT(*) FROM prospect_pages WHERE project_id = ? AND status = 'needs_review'"),
            'approved' => $p("SELECT COUNT(*) FROM prospect_pages WHERE project_id = ? AND status = 'approved'"),
            'held' => $p('SELECT COUNT(*) FROM prospect_pages WHERE project_id = ? AND manual_hold = 1'),
            'messages_sent' => $p("SELECT COUNT(*) FROM outreach_messages WHERE project_id = ? AND status = 'sent'"),
            'messages_pending' => $p("SELECT COUNT(*) FROM outreach_messages WHERE project_id = ? AND status = 'needs_review'"),
            'links_active' => $p("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND status IN ('active','changed_anchor','nofollow','redirected')"),
            'links_lost' => $p("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND lifecycle = 'lost'"),
            'links_unverified' => $p("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND status = 'unverified'"),
            'avg_score' => (int)round((float)$this->db->fetchColumn('SELECT AVG(quality_score) FROM prospect_pages WHERE project_id = ? AND quality_score IS NOT NULL', [$id])),
            'client_approvers' => $p('SELECT COUNT(*) FROM client_users cu JOIN projects pr ON pr.client_id = cu.client_id WHERE pr.id = ? AND cu.can_approve = 1'),
        ];
    }
}
