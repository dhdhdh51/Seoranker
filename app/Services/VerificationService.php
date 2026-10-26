<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\User;
use Flatmates\Support\Options;

/**
 * Student verification.
 *  1. College email: a one-time code sent to an address on a domain an administrator has mapped
 *     to a college. A verified address proves control of that mailbox on that domain — it is shown
 *     as "College email verified", not as proof of current enrolment (alumni/staff may keep addresses).
 *  2. Document review: a moderator with the "verifications" permission checks an uploaded college
 *     ID / admission letter. The file is stored outside the web root, every view is audited, and
 *     it is deleted as soon as a decision is made (only the decision is kept).
 */
final class VerificationService
{
    public function __construct(private App $app)
    {
    }

    public function isVerified(int $userId): bool
    {
        return (bool)$this->app->db()->fetchColumn("SELECT 1 FROM verifications WHERE user_id = ? AND status = 'approved' LIMIT 1", [$userId]);
    }

    public function forUser(int $userId): array
    {
        return $this->app->db()->fetchAll('SELECT id, method, college_email, document_type, status, note, created_at, reviewed_at FROM verifications WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    /** College whose configured email domain matches the address (exact domain or a subdomain). */
    public function collegeForEmail(string $email): ?array
    {
        $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return null;
        }
        foreach ($this->app->db()->fetchAll("SELECT * FROM colleges WHERE active = 1 AND email_domain IS NOT NULL AND email_domain <> ''") as $c) {
            $d = strtolower((string)$c['email_domain']);
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return $c;
            }
        }
        return null;
    }

    public function startCollegeEmail(User $u, string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::single('college_email', 'Enter your college email address.');
        }
        if ($this->isVerified($u->id)) {
            throw ValidationException::single('college_email', 'You are already verified.');
        }
        $college = $this->collegeForEmail($email);
        if (!$college) {
            throw ValidationException::single('college_email', 'We do not recognise this college email domain yet. Please verify with a college ID document instead.');
        }
        $db = $this->app->db();
        if ($db->fetchColumn("SELECT 1 FROM verifications WHERE college_email = ? AND status = 'approved' AND user_id <> ?", [$email, $u->id])) {
            throw ValidationException::single('college_email', 'This college email is already linked to another account.');
        }
        $this->app->make(OtpService::class)->issue($u->id, 'college_email', $email, 'Confirm your college email', 'Use this code to confirm your college email for ' . $college['name'] . ' on ' . brand() . '.');
        $_SESSION['_college_email'] = $email;
    }

    public function confirmCollegeEmail(User $u, string $code): void
    {
        $row = $this->app->make(OtpService::class)->verify($u->id, 'college_email', $code);
        $email = (string)$row['target_email'];
        $college = $this->collegeForEmail($email);
        if (!$college) {
            throw ValidationException::single('code', 'This college domain is no longer accepted.');
        }
        $db = $this->app->db();
        $db->insert('verifications', ['user_id' => $u->id, 'method' => 'college_email', 'college_email' => $email, 'status' => 'approved', 'reviewed_at' => gmdate('Y-m-d H:i:s'), 'note' => 'College email verified: ' . $college['name']]);
        $db->run('UPDATE profiles SET college_id = COALESCE(college_id, ?) WHERE user_id = ?', [(int)$college['id'], $u->id]);
        unset($_SESSION['_college_email']);
        $this->app->make(AuditLog::class)->log('verification.college_email', $u->id, 'user', $u->id, ['college_id' => (int)$college['id']]);
    }

    public function submitDocument(User $u, string $type, ?array $file): int
    {
        if (!in_array($type, Options::values('document_type'), true)) {
            throw ValidationException::single('document_type', 'Choose the document type.');
        }
        if ($this->isVerified($u->id)) {
            throw ValidationException::single('document', 'You are already verified.');
        }
        $db = $this->app->db();
        if ($db->fetchColumn("SELECT 1 FROM verifications WHERE user_id = ? AND method = 'document' AND status = 'pending'", [$u->id])) {
            throw ValidationException::single('document', 'Your document is already waiting for review.');
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::single('document', 'Choose a photo or PDF of your document.');
        }
        $path = $this->app->make(ImageService::class)->storeDocument($file, $u->id);
        $id = $db->insert('verifications', ['user_id' => $u->id, 'method' => 'document', 'document_type' => $type, 'document_path' => $path]);
        $this->app->make(Notifier::class)->notifyStaff('verifications', 'Student verification waiting for review', '/admin/verifications/' . $id);
        $this->app->make(AuditLog::class)->log('verification.submitted', $u->id, 'verification', $id);
        return $id;
    }

    public function pending(): array
    {
        return $this->app->db()->fetchAll("SELECT v.*, u.name, u.email, p.display_name, co.name AS college_name FROM verifications v JOIN users u ON u.id = v.user_id LEFT JOIN profiles p ON p.user_id = v.user_id LEFT JOIN colleges co ON co.id = p.college_id WHERE v.status = 'pending' ORDER BY v.created_at");
    }

    public function recent(int $limit = 30): array
    {
        return $this->app->db()->fetchAll("SELECT v.*, p.display_name, r.name AS reviewer_name FROM verifications v LEFT JOIN profiles p ON p.user_id = v.user_id LEFT JOIN users r ON r.id = v.reviewer_id WHERE v.status <> 'pending' ORDER BY COALESCE(v.reviewed_at, v.created_at) DESC LIMIT " . max(1, $limit));
    }

    public function find(User $staff, int $id): array
    {
        if (!$staff->can('verifications')) {
            throw new HttpException(403);
        }
        $v = $this->app->db()->fetch('SELECT v.*, u.name, u.email, u.created_at AS user_created, p.display_name, co.name AS college_name FROM verifications v JOIN users u ON u.id = v.user_id LEFT JOIN profiles p ON p.user_id = v.user_id LEFT JOIN colleges co ON co.id = p.college_id WHERE v.id = ?', [$id]);
        if (!$v) {
            throw new HttpException(404);
        }
        return $v;
    }

    /** @return array{path:string, mime:string} absolute path of the private document, audited. */
    public function document(User $staff, int $id): array
    {
        $v = $this->find($staff, $id);
        if (!$v['document_path'] || $v['status'] !== 'pending') {
            throw new HttpException(404, 'The document is no longer stored (it is deleted after review).');
        }
        $root = realpath($this->app->config->storagePath('private/verifications'));
        $path = realpath($this->app->config->storagePath((string)$v['document_path']));
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new HttpException(404);
        }
        $this->app->make(AuditLog::class)->log('verification.document_viewed', $staff->id, 'verification', $id);
        return ['path' => $path, 'mime' => str_ends_with($path, '.pdf') ? 'application/pdf' : 'image/jpeg'];
    }

    public function review(User $staff, int $id, string $decision, string $note = ''): void
    {
        $v = $this->find($staff, $id);
        if ($v['status'] !== 'pending') {
            throw ValidationException::single('decision', 'This verification was already reviewed.');
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new HttpException(400);
        }
        if ((int)$v['user_id'] === $staff->id) {
            throw new HttpException(403, 'You cannot review your own verification.');
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($decision === 'reject' && $note === '') {
            throw ValidationException::single('note', 'Tell the student why, so they can try again.');
        }
        $this->app->db()->update('verifications', ['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewer_id' => $staff->id, 'reviewed_at' => gmdate('Y-m-d H:i:s'), 'note' => $note ?: null, 'document_path' => null], 'id = ?', [$id]);
        $this->app->make(ImageService::class)->delete($v['document_path']);
        $this->app->make(Notifier::class)->notify((int)$v['user_id'], 'verification', $decision === 'approve' ? 'You are now a verified student ✔' : 'Your verification was not approved: ' . $note, '/verify');
        $this->app->make(AuditLog::class)->log('verification.' . $decision, $staff->id, 'verification', $id, ['user_id' => (int)$v['user_id']]);
    }
}
