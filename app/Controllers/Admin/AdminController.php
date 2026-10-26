<?php
declare(strict_types=1);

namespace Flatmates\Controllers\Admin;

use Flatmates\Controllers\Controller;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Http\Response;
use Flatmates\Services\AdminService;
use Flatmates\Services\AuditLog;
use Flatmates\Services\ListingService;
use Flatmates\Services\Mailer;
use Flatmates\Services\SafetyService;
use Flatmates\Services\SettingsService;
use Flatmates\Services\VerificationService;
use Flatmates\Support\Paginator;

final class AdminController extends Controller
{
    private function admin(): AdminService
    {
        return $this->app->make(AdminService::class);
    }

    private function render(string $tpl, array $data): Response
    {
        return $this->view('admin/' . $tpl, $data + ['noindex' => true, 'adminNav' => true]);
    }

    public function dashboard(): Response
    {
        $db = $this->app->db();
        return $this->render('dashboard', [
            'title' => 'Admin dashboard', 'stats' => $this->admin()->stats(),
            'recentReports' => $this->user()->can('reports') ? $this->app->make(SafetyService::class)->reports('open', 5) : [],
            'pendingVerifications' => $this->user()->can('verifications') ? array_slice($this->app->make(VerificationService::class)->pending(), 0, 5) : [],
            'pendingListings' => $this->user()->can('listings') ? $db->fetchAll("SELECT id, title, slug, created_at FROM listings WHERE status = 'pending' ORDER BY updated_at LIMIT 5") : [],
            'health' => $this->user()->isAdmin() ? array_values(array_filter($this->admin()->health(), static fn($c) => !$c[2])) : [],
        ]);
    }

    // --- reports ---
    public function reports(): Response
    {
        $status = in_array($this->req->query('status'), ['open', 'actioned', 'dismissed', 'escalated', 'all'], true) ? (string)$this->req->query('status') : 'open';
        return $this->render('reports', ['title' => 'Reports', 'status' => $status, 'rows' => $this->app->make(SafetyService::class)->reports($status, 100, 0)]);
    }

    public function report(int $id): Response
    {
        $svc = $this->app->make(SafetyService::class);
        $r = $svc->find($id);
        if (!$r) {
            throw new HttpException(404);
        }
        return $this->render('report', ['title' => 'Report #' . $id, 'r' => $r, 'evidence' => $svc->evidence($r)]);
    }

    public function decideReport(int $id): Response
    {
        $this->app->make(SafetyService::class)->decide($this->user(), $id, (string)$this->req->input('action', ''), (string)$this->req->input('note', ''));
        $this->flash('success', 'Decision saved.');
        return $this->redirect('/admin/reports');
    }

    // --- verifications ---
    public function verifications(): Response
    {
        $svc = $this->app->make(VerificationService::class);
        return $this->render('verifications', ['title' => 'Verifications', 'pending' => $svc->pending(), 'recent' => $svc->recent()]);
    }

    public function verification(int $id): Response
    {
        $v = $this->app->make(VerificationService::class)->find($this->user(), $id);
        return $this->render('verification', ['title' => 'Verification #' . $id, 'v' => $v]);
    }

    public function document(int $id): Response
    {
        $doc = $this->app->make(VerificationService::class)->document($this->user(), $id);
        $body = (string)file_get_contents($doc['path']);
        return new Response($body, 200, ['Content-Type' => $doc['mime'], 'Content-Disposition' => 'inline; filename="document"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'", 'X-Robots-Tag' => 'noindex']);
    }

    public function reviewVerification(int $id): Response
    {
        $this->app->make(VerificationService::class)->review($this->user(), $id, (string)$this->req->input('decision', ''), (string)$this->req->input('note', ''));
        $this->flash('success', 'Verification reviewed; the document has been deleted.');
        return $this->redirect('/admin/verifications');
    }

    // --- listings ---
    public function listings(): Response
    {
        $status = (string)$this->req->query('status', 'pending');
        $r = $this->app->make(ListingService::class)->adminList($status === 'all' ? '' : $status, $this->page());
        return $this->render('listings', ['title' => 'Listings', 'status' => $status, 'rows' => $r['rows'], 'pager' => new Paginator($r['total'], $this->page(), 30)]);
    }

    public function moderateListing(int $id): Response
    {
        $this->app->make(ListingService::class)->moderate($this->user(), $id, (string)$this->req->input('action', ''), (string)$this->req->input('note', ''));
        $this->flash('success', 'Listing updated.');
        return $this->back();
    }

    public function deletePhoto(int $id): Response
    {
        $this->app->make(ListingService::class)->removePhotoAsStaff($this->user(), $id);
        $this->flash('success', 'Photo removed.');
        return $this->back();
    }

    // --- users ---
    public function users(): Response
    {
        $f = ['q' => (string)$this->req->query('q', ''), 'status' => (string)$this->req->query('status', ''), 'role' => (string)$this->req->query('role', '')];
        $r = $this->admin()->users($f, $this->page());
        return $this->render('users', ['title' => 'Users', 'f' => $f, 'rows' => $r['rows'], 'pager' => new Paginator($r['total'], $this->page(), 30)]);
    }

    public function showUser(int $id): Response
    {
        $u = $this->admin()->user($id);
        $this->app->make(AuditLog::class)->log('admin.user_viewed', $this->user()->id, 'user', $id);
        return $this->render('user', ['title' => $u['name'], 'u' => $u]);
    }

    public function userStatus(int $id): Response
    {
        $this->admin()->setStatus($this->user(), $id, (string)$this->req->input('status', ''), (string)$this->req->input('reason', ''));
        $this->flash('success', 'Account status updated.');
        return $this->redirect('/admin/users/' . $id);
    }

    public function userRole(int $id): Response
    {
        $perms = $this->req->input('permissions', []);
        $this->admin()->setRole($this->user(), $id, (string)$this->req->input('role', ''), is_array($perms) ? $perms : []);
        $this->flash('success', 'Role updated.');
        return $this->redirect('/admin/users/' . $id);
    }

    public function createStaff(): Response
    {
        $perms = $this->req->input('permissions', []);
        $id = $this->admin()->createStaff($this->user(), (string)$this->req->input('name', ''), (string)$this->req->input('email', ''), (string)$this->req->input('password', ''), (string)$this->req->input('role', 'moderator'), is_array($perms) ? $perms : []);
        $this->flash('success', 'Staff account created.');
        return $this->redirect('/admin/users/' . $id);
    }

    // --- locations ---
    public function locations(): Response
    {
        $db = $this->app->db();
        return $this->render('locations', ['title' => 'Cities & colleges', 'cities' => $db->fetchAll('SELECT * FROM cities ORDER BY name'), 'colleges' => $db->fetchAll('SELECT co.*, c.name AS city_name FROM colleges co JOIN cities c ON c.id = co.city_id ORDER BY c.name, co.name')]);
    }

    public function saveLocation(): Response
    {
        $db = $this->app->db();
        $kind = (string)$this->req->input('kind', '');
        $id = (int)($this->req->inputInt('id') ?? 0);
        $name = trim((string)$this->req->input('name', ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 190) {
            throw ValidationException::single('name', 'Enter a name.');
        }
        $active = $this->req->input('active') ? 1 : 0;
        if ($kind === 'city') {
            $data = ['name' => $name, 'state' => mb_substr(trim((string)$this->req->input('state', '')), 0, 100), 'intro' => mb_substr(trim((string)$this->req->input('intro', '')), 0, 3000) ?: null, 'active' => $active];
            if ($id) {
                $db->update('cities', $data, 'id = ?', [$id]);
            } else {
                $db->insert('cities', $data + ['slug' => ListingService::slugify($name)]);
            }
        } elseif ($kind === 'college') {
            $domain = strtolower(trim((string)$this->req->input('email_domain', '')));
            if ($domain !== '' && !preg_match('/^(?=.{4,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
                throw ValidationException::single('email_domain', 'Email domain must look like amity.edu (no @).');
            }
            if (in_array($domain, ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'rediffmail.com', 'icloud.com', 'proton.me'], true)) {
                throw ValidationException::single('email_domain', 'Public email providers cannot be used as college domains.');
            }
            $cityId = (int)$this->req->inputInt('city_id');
            if (!$db->fetchColumn('SELECT 1 FROM cities WHERE id = ?', [$cityId])) {
                throw ValidationException::single('city_id', 'Choose a city.');
            }
            $data = ['name' => $name, 'city_id' => $cityId, 'email_domain' => $domain ?: null, 'active' => $active];
            if ($id) {
                $db->update('colleges', $data, 'id = ?', [$id]);
            } else {
                $db->insert('colleges', $data + ['slug' => ListingService::slugify($name)]);
            }
        } else {
            throw new HttpException(400);
        }
        $this->app->make(AuditLog::class)->log('admin.location_saved', $this->user()->id, $kind, $id ?: null, ['name' => $name]);
        $this->flash('success', ucfirst($kind) . ' saved.');
        return $this->redirect('/admin/locations');
    }

    // --- settings ---
    public function settings(): Response
    {
        $groups = [];
        foreach (SettingsService::DEFINITIONS as $key => $d) {
            $groups[$d['group']][$key] = $d;
        }
        return $this->render('settings', ['title' => 'Settings', 'groups' => $groups, 'settings' => $this->app->settings(), 'mailConfigured' => $this->app->make(Mailer::class)->isConfigured()]);
    }

    public function saveSettings(): Response
    {
        $s = $this->app->settings();
        $errors = [];
        $changed = [];
        $group = (string)$this->req->input('group', '');
        foreach (SettingsService::DEFINITIONS as $key => $d) {
            if ($d['group'] !== $group) {
                continue;
            }
            $field = str_replace('.', '__', $key);
            $raw = $this->req->input($field, $d['type'] === 'bool' ? '0' : null);
            if ($raw === null || ($d['type'] === 'secret' && $raw === '')) {
                continue; // secrets are only replaced when a new value is typed
            }
            [$ok, $value, $err] = SettingsService::validate($key, $raw);
            if (!$ok) {
                $errors[$field] = (string)$err;
                continue;
            }
            if ($d['type'] === 'secret' && $key === 'sso.secret' && strlen((string)$value) < 32) {
                $errors[$field] = 'The SSO secret must be at least 32 characters.';
                continue;
            }
            $s->set($key, $value);
            $changed[] = $key;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $this->app->make(AuditLog::class)->log('admin.settings_saved', $this->user()->id, null, null, ['keys' => $changed]);
        $this->flash('success', 'Settings saved.');
        return $this->redirect('/admin/settings', ['g' => $group]);
    }

    public function testEmail(): Response
    {
        $mailer = $this->app->make(Mailer::class);
        $id = $mailer->send($this->user()->email, 'Test email from ' . brand(), 'If you can read this, email delivery works.');
        $row = $this->app->db()->fetch('SELECT status, last_error FROM mail_queue WHERE id = ?', [$id]);
        if ($row['status'] === 'sent') {
            $this->flash('success', 'Test email sent to ' . $this->user()->email . '.');
        } else {
            $this->flash('danger', 'Sending failed: ' . ($row['last_error'] ?: 'unknown error') . ' (it will be retried by cron).');
        }
        return $this->redirect('/admin/settings', ['g' => 'Email (SMTP)']);
    }

    public function analytics(): Response
    {
        return $this->render('analytics', ['title' => 'Analytics', 'a' => $this->admin()->analytics(30)]);
    }

    public function audit(): Response
    {
        $f = ['action' => (string)$this->req->query('action', ''), 'actor' => (string)$this->req->query('actor', '')];
        $r = $this->admin()->auditLogs($f, $this->page());
        return $this->render('audit', ['title' => 'Audit log', 'f' => $f, 'rows' => $r['rows'], 'pager' => new Paginator($r['total'], $this->page(), 50)]);
    }

    public function health(): Response
    {
        $cronSecret = (string)$this->app->config->get('cron.web_trigger_secret', '');
        return $this->render('health', ['title' => 'System health', 'checks' => $this->admin()->health(), 'webCron' => (bool)$this->app->config->get('cron.web_trigger_enabled'), 'php' => PHP_BINARY, 'root' => APP_ROOT, 'hasSecret' => strlen($cronSecret) >= 32]);
    }
}
