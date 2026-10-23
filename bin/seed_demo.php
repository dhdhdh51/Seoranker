<?php
declare(strict_types=1);

/**
 * Seeds clearly-labelled DEMO data (names prefixed "[DEMO]", reserved example.* domains).
 * Refuses to run unless config app.demo_mode = true AND app.env != production.
 * No network access: pages are served by an in-memory fetcher through the real pipeline.
 *
 *   php bin/seed_demo.php
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\PageFetcher;
use BharatSeo\Security\Auth;
use BharatSeo\Security\User;
use BharatSeo\Services\ApprovalService;
use BharatSeo\Services\BacklinkMonitor;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Services\EvaluationService;
use BharatSeo\Services\OutreachService;
use BharatSeo\Services\ProjectService;
use BharatSeo\Services\ProspectService;

$app = bharatseo_boot();
if (!$app->config->get('app.demo_mode') || $app->config->isProduction()) {
    fwrite(STDERR, "Refusing: set app.demo_mode = true and app.env = development in the config to seed demo data.\n");
    exit(2);
}
$db = $app->db();
if ($db->fetchColumn("SELECT 1 FROM clients WHERE name LIKE '[DEMO]%'")) {
    fwrite(STDOUT, "Demo data already present.\n");
    exit(0);
}

final class DemoFetcher implements PageFetcher
{
    public array $pages = [];

    public function fetch(string $url, array $options = []): HttpResponse
    {
        if (isset($this->pages[$url])) {
            [$status, $html] = $this->pages[$url];
            return new HttpResponse($url, $url, $status, ['content-type' => 'text/html'], $html, null, null, [], random_int(200, 900));
        }
        return new HttpResponse($url, $url, 404, ['content-type' => 'text/html'], '<html><body>Not found</body></html>');
    }
}

$fetcher = new DemoFetcher();
$app->bind(HttpClient::class, fn() => $fetcher);
$app->forget(BacklinkMonitor::class);

$admin = User::fromRow($db->fetch("SELECT * FROM users WHERE role = 'super_admin' ORDER BY id LIMIT 1"));
$passwords = [];
$mkUser = static function (string $role, string $name, string $email) use ($db, &$passwords): int {
    $pw = 'Demo-' . bin2hex(random_bytes(5)) . '9';
    $passwords[$email] = $pw;
    return $db->insert('users', ['name' => $name, 'email' => $email, 'password_hash' => Auth::hashPassword($pw), 'role' => $role, 'status' => 'active']);
};
$managerId = $mkUser('seo_manager', '[DEMO] Rohan Manager', 'demo.manager@example.com');
$clientUserId = $mkUser('client', '[DEMO] Kavya Client', 'demo.client@example.com');

$clientId = $db->insert('clients', ['name' => '[DEMO] Desi Organics Pvt Ltd', 'company' => 'Desi Organics', 'contact_email' => 'owner@example.com', 'status' => 'active', 'created_by' => $admin->id]);
$client2 = $db->insert('clients', ['name' => '[DEMO] Mumbai Tech Repairs', 'status' => 'active', 'created_by' => $admin->id]);
$db->insert('client_users', ['client_id' => $clientId, 'user_id' => $clientUserId, 'can_approve' => 1, 'can_export' => 1]);

$projects = $app->make(ProjectService::class);
$p1 = $projects->create($admin, ['client_id' => $clientId, 'name' => '[DEMO] Desi Organics store', 'website_url' => 'https://desi-organics.example.com/', 'niche' => 'organic food', 'brand_name' => 'Desi Organics', 'preferred_anchors' => "Desi Organics\ndesi-organics.example.com", 'keywords' => "organic food\nmillets\ncold pressed oil", 'topics' => "healthy cooking\nsustainable farming", 'target_pages' => "https://desi-organics.example.com/guides/millets/\nhttps://desi-organics.example.com/", 'contact_email' => 'hello@example.com', 'outreach_from_name' => 'Rohan (Desi Organics)', 'approval_policy' => 'manager_and_client']);
$p2 = $projects->create($admin, ['client_id' => $client2, 'name' => '[DEMO] Tech repairs', 'website_url' => 'https://techfix.example.net/', 'niche' => 'phone repair', 'keywords' => "phone repair\nscreen replacement", 'approval_policy' => 'manager']);
$db->insert('project_members', ['project_id' => $p1, 'user_id' => $managerId, 'role' => 'manager']);

$body = static function (string $title, string $topic, int $paras, string $extra = ''): string {
    $p = '';
    for ($i = 0; $i < $paras; $i++) {
        $p .= "<p>Paragraph $i about $topic: practical, original advice on $topic for families in India, with examples and sources number $i.</p>";
    }
    return "<html><head><title>$title</title><meta name=\"description\" content=\"$topic resources\"><meta name=\"author\" content=\"Editor\"><meta property=\"og:site_name\" content=\"$title\"></head><body><nav><a href=\"/about/\">About</a> <a href=\"/contact/\">Contact</a></nav><article class=\"content\"><h1>$title</h1><time datetime=\"2026-05-01\"></time>$p<a href=\"https://nutrition.example.org/guide\">Nutrition guide</a> <a href=\"https://farmers.example.org/\">Farmers network</a>$extra<p>Write to editor@" . 'example.com' . " · contributor guidelines</p></article></body></html>";
};
$pages = [
    'https://healthy-kitchen.example.com/resources/' => [200, $body('Healthy Kitchen Resources', 'millets and organic food', 45)],
    'https://green-living.example.org/write-for-us/' => [200, $body('Green Living Blog', 'sustainable farming and organic food', 30)],
    'https://spam-directory.example.net/links/' => [200, $body('Cheap Links Directory', 'casino loans', 5, '<div style="display:none"><a href="https://x.example.com/">hidden</a></div>')],
    'https://thin.example.com/page' => [200, '<html><head><title>Phone cases</title></head><body><p>Buy now.</p></body></html>'],
    'https://millet-mentions.example.org/news/' => [200, $body('Millet News', 'millets', 25, '<p>Desi Organics sells millets.</p>')],
];
$fetcher->pages = $pages;
$ps = $app->make(ProspectService::class);
$eval = $app->make(EvaluationService::class);
$cats = ['resource_page', 'guest_post', 'business_directory', 'other', 'unlinked_mention'];
$i = 0;
$prospectIds = [];
foreach (array_keys($pages) as $url) {
    $r = $ps->createFromUrl($projects->get($p1), $url, ['category' => $cats[$i++], 'source' => 'manual', 'evidence' => '[DEMO] sample opportunity'], null, $managerId);
    if ($r['id']) {
        $eval->evaluate((int)$r['id']);
        $prospectIds[] = (int)$r['id'];
    }
}
$manager = User::fromRow($db->fetch('SELECT * FROM users WHERE id = ?', [$managerId]));
$app->make(\BharatSeo\Security\Gate::class)->flush();
$approvals = $app->make(ApprovalService::class);
$first = $prospectIds[0];
if ($ps->get($first)['status'] === 'needs_review') {
    $approvals->decideProspect($manager, $first, true, '[DEMO] good fit');
}
$campaign = $app->make(OutreachService::class)->saveCampaign($manager, null, ['project_id' => $p1, 'name' => '[DEMO] Resource page outreach', 'type' => 'resource_page', 'status' => 'active', 'follow_up_days' => 7, 'max_follow_ups' => 1, 'auto_drafts' => '1']);
if ($ps->get($first)['status'] === 'approved') {
    $mid = $app->make(OutreachService::class)->createDraft($manager, $campaign, $first);
    $app->make(OutreachService::class)->submitForApproval($manager, $mid);
}
// A tracked backlink with history.
$ref = 'https://healthy-kitchen.example.com/resources/';
$fetcher->pages[$ref] = [200, $pages[$ref][1] . '<a href="https://desi-organics.example.com/guides/millets/">Desi Organics millet guide</a>'];
$bl = $app->make(BacklinkService::class)->createRecord($projects->get($p1), $ref, 'https://desi-organics.example.com/guides/millets/', 'Desi Organics millet guide', 'outreach', $first, $managerId);
if ($bl['id']) {
    $app->make(BacklinkMonitor::class)->check((int)$bl['id']);
}
fwrite(STDOUT, "Demo data created (all names prefixed [DEMO]).\nDemo logins (shown once):\n");
foreach ($passwords as $email => $pw) {
    fwrite(STDOUT, "  $email  /  $pw\n");
}
