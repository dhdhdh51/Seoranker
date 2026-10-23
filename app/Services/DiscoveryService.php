<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\Url;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Support\Csv;
use BharatSeo\Support\Labels;
use BharatSeo\Support\Validator;

/**
 * Discovery sources: manual URLs, CSV imports (prospect lists, public datasets,
 * competitor backlink exports), search-provider APIs and public page link extraction.
 * Google result pages are never scraped and access controls are never bypassed.
 */
final class DiscoveryService
{
    public const IMPORT_CHUNK_ROWS = 200;
    public const IMPORT_CHUNK_SECONDS = 20;

    /** Header aliases (normalized keys), in priority order. */
    public const ALIASES = [
        'url' => ['url', 'prospect_url', 'page_url', 'referring_page_url', 'referring_page', 'source_url', 'source_page', 'linking_page', 'linking_url', 'from_url', 'referring_url', 'page', 'website', 'site', 'link', 'source', 'domain'],
        'title' => ['title', 'page_title', 'referring_page_title', 'source_title', 'source_page_title', 'name_of_page'],
        'category' => ['category', 'opportunity_type', 'type'],
        'contact_email' => ['contact_email', 'email', 'e_mail', 'email_address'],
        'contact_name' => ['contact_name', 'contact', 'editor', 'author_name'],
        'evidence' => ['evidence', 'reason', 'opportunity'],
        'notes' => ['notes', 'note', 'comments'],
        'target_url' => ['target_page', 'landing_page', 'our_page', 'proposed_target'],
        'anchor' => ['anchor', 'anchor_text', 'link_anchor'],
        'competitor_target' => ['target_url', 'link_url_target', 'destination_url', 'destination', 'to_url'],
    ];

    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    private function project(User $user, int $projectId): array
    {
        return $this->app->make(ProjectService::class)->find($user, $projectId, Gate::MANAGE);
    }

    public function assertModule(): void
    {
        if (!$this->app->settings()->moduleEnabled('discovery')) {
            throw new HttpException(403, 'The discovery module is disabled by the administrator.');
        }
    }

    public function startRun(array $project, string $source, ?string $detail, ?int $userId, ?string $category = null, string $status = 'running'): int
    {
        return $this->db->insert('discovery_runs', [
            'project_id' => (int)$project['id'],
            'client_id' => (int)$project['client_id'],
            'source_type' => $source,
            'source_detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
            'default_category' => $category,
            'status' => $status,
            'started_by' => $userId,
            'started_at' => $status === 'running' ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    public function finishRun(int $runId, array $counts, ?string $error = null): void
    {
        $this->db->run(
            'UPDATE discovery_runs SET total_input = total_input + ?, created_count = created_count + ?, duplicate_count = duplicate_count + ?, rejected_count = rejected_count + ?,
                status = ?, error_message = ?, finished_at = UTC_TIMESTAMP(), started_at = COALESCE(started_at, UTC_TIMESTAMP()) WHERE id = ?',
            [$counts['total'] ?? 0, $counts['created'] ?? 0, $counts['duplicate'] ?? 0, $counts['rejected'] ?? 0, $error ? 'failed' : 'completed', $error, $runId]
        );
    }

    public function incrementRun(int $runId, array $counts): void
    {
        $this->db->run(
            "UPDATE discovery_runs SET total_input = total_input + ?, created_count = created_count + ?, duplicate_count = duplicate_count + ?, rejected_count = rejected_count + ?, status = 'running', started_at = COALESCE(started_at, UTC_TIMESTAMP()) WHERE id = ?",
            [$counts['total'] ?? 0, $counts['created'] ?? 0, $counts['duplicate'] ?? 0, $counts['rejected'] ?? 0, $runId]
        );
    }

    /**
     * Manual URL entry.
     * @return array{total:int, created:int, duplicate:int, rejected:int, errors:string[], run_id:int}
     */
    public function addManual(User $user, int $projectId, array $input): array
    {
        $this->assertModule();
        $project = $this->project($user, $projectId);
        $category = isset(Labels::CATEGORIES[$input['category'] ?? '']) ? $input['category'] : 'other';
        $urls = Validator::lines((string)($input['urls'] ?? ''), 501);
        if ($urls === []) {
            throw ValidationException::single('urls', 'Enter at least one URL (one per line).');
        }
        if (count($urls) > 500) {
            throw ValidationException::single('urls', 'Enter at most 500 URLs at a time; use CSV import for larger lists.');
        }
        $contact = trim((string)($input['contact_email'] ?? ''));
        $meta = [
            'category' => $category,
            'source' => 'manual',
            'evidence' => mb_substr(trim((string)($input['evidence'] ?? '')), 0, 5000),
            'notes' => mb_substr(trim((string)($input['notes'] ?? '')), 0, 5000),
            'contact_email' => count($urls) === 1 && filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : null,
            'target_page_id' => (int)($input['target_page_id'] ?? 0) ?: null,
        ];
        $runId = $this->startRun($project, 'manual', count($urls) . ' URL(s) entered manually', $user->id, $category);
        $res = $this->ingest($project, $urls, $meta, $runId, $user->id);
        $this->finishRun($runId, $res);
        $this->app->make(AuditLogger::class)->log('discovery.manual', $user->id, $projectId, ['run_id' => $runId] + array_diff_key($res, ['errors' => 1]), 'discovery_run', null, $runId);
        return $res + ['run_id' => $runId];
    }

    /** @return array{total:int, created:int, duplicate:int, rejected:int, errors:string[]} */
    public function ingest(array $project, array $urls, array $meta, ?int $runId, ?int $userId): array
    {
        $prospects = $this->app->make(ProspectService::class);
        $exclusions = $this->app->make(ProjectService::class)->exclusions($project);
        $res = ['total' => 0, 'created' => 0, 'duplicate' => 0, 'rejected' => 0, 'errors' => []];
        foreach ($urls as $u) {
            $res['total']++;
            $rowMeta = is_array($u) ? $u + $meta : ['original_url' => $u] + $meta;
            $url = is_array($u) ? (string)$u['url'] : (string)$u;
            $d = Url::domain((string)(Url::normalize($url) ?? ''));
            if ($d !== null && self::isExcluded($d, $exclusions)) {
                $res['rejected']++;
                $res['errors'][] = mb_substr($url, 0, 100) . ': domain is excluded for this project';
                continue;
            }
            $r = $prospects->createFromUrl($project, $url, $rowMeta, $runId, $userId);
            $res[$r['status']]++;
            if ($r['status'] === 'rejected' && count($res['errors']) < 50) {
                $res['errors'][] = mb_substr($url, 0, 100) . ': ' . $r['reason'];
            }
        }
        return $res;
    }

    public static function isExcluded(string $domain, array $exclusions): bool
    {
        foreach ($exclusions as $ex) {
            if ($domain === $ex || str_ends_with($domain, '.' . $ex)) {
                return true;
            }
        }
        return false;
    }

    /** Store an uploaded CSV and queue it for chunked processing. */
    public function queueImport(User $user, int $projectId, array $file, string $type, ?string $defaultCategory): int
    {
        $this->assertModule();
        $project = $this->project($user, $projectId);
        if (!in_array($type, ['prospects', 'competitor_backlinks', 'dataset', 'prospect_list'], true)) {
            throw ValidationException::single('type', 'Invalid import type.');
        }
        if ($defaultCategory !== null && $defaultCategory !== '' && !isset(Labels::CATEGORIES[$defaultCategory])) {
            throw ValidationException::single('category', 'Invalid category.');
        }
        $maxBytes = (int)$this->app->settings()->get('security.upload_max_mb', 5) * 1048576;
        $stored = Csv::storeUpload($file, $this->app->config->storagePath('uploads/imports'), $maxBytes);
        $existing = $this->db->fetch('SELECT id FROM import_jobs WHERE project_id = ? AND file_sha256 = ? AND type = ?', [$projectId, $stored['sha256'], $type]);
        if ($existing) {
            @unlink($stored['path']);
            throw ValidationException::single('file', 'This exact file was already imported into this project (import #' . $existing['id'] . ').');
        }
        $source = match ($type) {
            'competitor_backlinks' => 'competitor_export',
            'dataset' => 'dataset',
            'prospect_list' => 'prospect_list',
            default => 'csv',
        };
        $id = $this->db->transaction(function (Database $db) use ($project, $user, $stored, $type, $defaultCategory, $source): int {
            $runId = $this->startRun($project, $source, 'File: ' . $stored['original'], $user->id, $defaultCategory ?: null, 'queued');
            return $db->insert('import_jobs', [
                'project_id' => (int)$project['id'],
                'client_id' => (int)$project['client_id'],
                'user_id' => $user->id,
                'discovery_run_id' => $runId,
                'type' => $type,
                'original_filename' => $stored['original'],
                'stored_path' => basename($stored['path']),
                'file_sha256' => $stored['sha256'],
                'default_category' => $defaultCategory ?: null,
            ]);
        });
        $this->app->make(QueueService::class)->push('process_import', ['import_id' => $id], $projectId, 'import:' . $id, 0, 4);
        $this->app->make(AuditLogger::class)->log('discovery.import_queued', $user->id, $projectId, ['import_id' => $id, 'type' => $type, 'file' => $stored['original']], 'import_job', null, $id);
        return $id;
    }

    /**
     * Process one bounded chunk of an import. Returns true when the import is finished.
     */
    public function processImportChunk(int $importId): bool
    {
        $job = $this->db->fetch('SELECT * FROM import_jobs WHERE id = ?', [$importId]);
        if (!$job || in_array($job['status'], ['completed', 'failed'], true)) {
            return true;
        }
        $project = $this->db->fetch('SELECT * FROM projects WHERE id = ?', [$job['project_id']]);
        $path = $this->app->config->storagePath('uploads/imports/' . basename((string)$job['stored_path']));
        if (!$project || !is_file($path)) {
            $this->db->update('import_jobs', ['status' => 'failed', 'errors' => json_encode(['Import file is missing.']), 'finished_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$importId]);
            if ($job['discovery_run_id']) {
                $this->finishRun((int)$job['discovery_run_id'], [], 'Import file is missing.');
            }
            return true;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open import file.');
        }
        $errors = json_decode((string)($job['errors'] ?? '[]'), true) ?: [];
        try {
            $firstLine = (string)fgets($fh);
            $delimiter = Csv::detectDelimiter($firstLine);
            rewind($fh);
            $headers = fgetcsv($fh, 0, $delimiter, '"', '\\') ?: [];
            $map = Csv::mapHeaders($headers, self::ALIASES);
            if (!isset($map['url'])) {
                $msg = 'No URL column found. Expected a header such as "url", "Referring page URL" or "Source URL".';
                $this->db->update('import_jobs', ['status' => 'failed', 'errors' => json_encode([$msg]), 'finished_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$importId]);
                if ($job['discovery_run_id']) {
                    $this->finishRun((int)$job['discovery_run_id'], [], $msg);
                }
                return true;
            }
            if ((int)$job['byte_offset'] > 0) {
                fseek($fh, (int)$job['byte_offset']);
            }
            $this->db->update('import_jobs', ['status' => 'processing', 'header_map' => json_encode($map)], 'id = ?', [$importId]);
            $source = match ($job['type']) {
                'competitor_backlinks' => 'competitor_export',
                'dataset' => 'dataset',
                'prospect_list' => 'prospect_list',
                default => 'csv',
            };
            $defaultCategory = $job['default_category'] ?: ($job['type'] === 'competitor_backlinks' ? 'resource_page' : 'other');
            $batch = [];
            $rows = 0;
            $deadline = microtime(true) + self::IMPORT_CHUNK_SECONDS;
            while ($rows < self::IMPORT_CHUNK_ROWS && microtime(true) < $deadline && ($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                $rows++;
                $get = static fn(string $k): string => isset($map[$k]) ? trim((string)($row[$map[$k]] ?? '')) : '';
                $url = $get('url');
                if ($url === '') {
                    $errors[] = 'Row skipped: empty URL';
                    continue;
                }
                $catKey = Csv::headerKey($get('category'));
                $cat = isset(Labels::CATEGORIES[$catKey]) ? $catKey : $defaultCategory;
                $evidence = $get('evidence');
                if ($job['type'] === 'competitor_backlinks') {
                    $bits = ['Competitor backlink export: this page links to a competitor'];
                    if ($get('competitor_target') !== '') {
                        $bits[] = 'target ' . mb_substr($get('competitor_target'), 0, 200);
                    }
                    if ($get('anchor') !== '') {
                        $bits[] = 'anchor “' . mb_substr($get('anchor'), 0, 100) . '”';
                    }
                    $evidence = trim(implode(', ', $bits) . '. ' . $evidence);
                }
                $batch[] = [
                    'url' => $url,
                    'original_url' => $url,
                    'title' => $get('title') ?: null,
                    'category' => $cat,
                    'contact_email' => $get('contact_email') ?: null,
                    'contact_name' => $get('contact_name') ?: null,
                    'evidence' => $evidence,
                    'notes' => $get('notes'),
                    'target_url' => $get('target_url') ?: null,
                ];
            }
            $offset = ftell($fh);
            $eof = feof($fh) || ($row ?? null) === false;
            $res = $this->ingest($project, $batch, ['source' => $source], $job['discovery_run_id'] ? (int)$job['discovery_run_id'] : null, $job['user_id'] ? (int)$job['user_id'] : null);
            $errors = array_slice(array_merge($errors, $res['errors']), 0, 200);
            $this->db->run(
                'UPDATE import_jobs SET byte_offset = ?, total_rows = total_rows + ?, created_rows = created_rows + ?, duplicate_rows = duplicate_rows + ?, error_rows = error_rows + ?, errors = ? WHERE id = ?',
                [$offset, $res['total'], $res['created'], $res['duplicate'], $res['rejected'], json_encode($errors, JSON_UNESCAPED_UNICODE), $importId]
            );
            if ($job['discovery_run_id']) {
                $this->incrementRun((int)$job['discovery_run_id'], $res);
            }
            if ($eof) {
                $this->db->update('import_jobs', ['status' => 'completed', 'finished_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$importId]);
                if ($job['discovery_run_id']) {
                    $this->finishRun((int)$job['discovery_run_id'], []);
                }
                $this->app->make(NotificationService::class)->notifyProject((int)$job['project_id'], 'import_completed', 'Import completed: ' . $job['original_filename'], null, url('/discovery', ['project_id' => $job['project_id']]));
                if ($job['user_id']) {
                    $this->app->make(NotificationService::class)->notify((int)$job['user_id'], 'import_completed', 'Import completed: ' . $job['original_filename'], null, url('/discovery', ['project_id' => $job['project_id']]), (int)$job['project_id']);
                }
            }
            return $eof;
        } finally {
            fclose($fh);
        }
    }

    public function runs(User $user, ?int $projectId, int $limit = 30): array
    {
        [$scope, $params] = $this->app->make(Gate::class)->scope($user, 'r.project_id');
        $sql = "SELECT r.*, p.name AS project_name, u.name AS user_name FROM discovery_runs r JOIN projects p ON p.id = r.project_id LEFT JOIN users u ON u.id = r.started_by WHERE 1=1$scope";
        if ($projectId) {
            $sql .= ' AND r.project_id = ?';
            $params[] = $projectId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY r.id DESC LIMIT ' . max(1, $limit), $params);
    }

    public function imports(User $user, ?int $projectId, int $limit = 20): array
    {
        [$scope, $params] = $this->app->make(Gate::class)->scope($user, 'i.project_id');
        $sql = "SELECT i.*, p.name AS project_name FROM import_jobs i JOIN projects p ON p.id = i.project_id WHERE 1=1$scope";
        if ($projectId) {
            $sql .= ' AND i.project_id = ?';
            $params[] = $projectId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY i.id DESC LIMIT ' . max(1, $limit), $params);
    }

    // ----------------------------------------------------------------- search API

    /** Queue a search-provider discovery run with footprint queries built from project keywords. */
    public function queueSearch(User $user, int $projectId, array $input): int
    {
        $this->assertModule();
        $project = $this->project($user, $projectId);
        $search = $this->app->make(SearchProviderService::class);
        if (!$search->isAvailable()) {
            throw ValidationException::single('provider', 'No search provider is configured. A Super Admin can configure Google Programmable Search, Brave Search or an authorized SearXNG instance in Settings.');
        }
        $category = isset(Labels::CATEGORIES[$input['category'] ?? '']) ? $input['category'] : 'resource_page';
        $custom = Validator::lines((string)($input['queries'] ?? ''), 20, 200);
        $queries = $custom ?: SearchProviderService::footprints($project, $this->app->make(ProjectService::class)->keywords($projectId), $category);
        if ($queries === []) {
            throw ValidationException::single('queries', 'Add project keywords or enter at least one query.');
        }
        $queries = array_slice($queries, 0, 10);
        $runId = $this->startRun($project, 'search_api', $search->providerName() . ': ' . implode(' | ', $queries), $user->id, $category, 'queued');
        $this->app->make(QueueService::class)->push('search_discovery', ['run_id' => $runId, 'queries' => $queries, 'category' => $category], $projectId, 'search:' . $runId, 0, 5);
        $this->app->make(AuditLogger::class)->log('discovery.search_queued', $user->id, $projectId, ['run_id' => $runId, 'queries' => $queries], 'discovery_run', null, $runId);
        return $runId;
    }

    /** Queue extraction of outbound links from a public page (e.g. a resource list or association directory). */
    public function queuePublicPage(User $user, int $projectId, array $input): int
    {
        $this->assertModule();
        $project = $this->project($user, $projectId);
        $url = Url::normalize((string)($input['page_url'] ?? ''));
        if ($url === null) {
            throw ValidationException::single('page_url', 'Enter a valid public page URL.');
        }
        $category = isset(Labels::CATEGORIES[$input['category'] ?? '']) ? $input['category'] : 'resource_page';
        $runId = $this->startRun($project, 'public_page', $url, $user->id, $category, 'queued');
        $this->app->make(QueueService::class)->push('extract_public_page', ['run_id' => $runId, 'url' => $url, 'category' => $category, 'same_topic_only' => !empty($input['same_topic_only'])], $projectId, 'public_page:' . $runId, 0, 5);
        $this->app->make(AuditLogger::class)->log('discovery.public_page_queued', $user->id, $projectId, ['run_id' => $runId, 'url' => $url], 'discovery_run', null, $runId);
        return $runId;
    }
}
