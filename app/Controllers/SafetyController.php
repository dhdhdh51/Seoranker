<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Http\Response;
use Flatmates\Services\SafetyService;
use Flatmates\Services\VerificationService;

final class SafetyController extends Controller
{
    public function block(int $id): Response
    {
        $this->app->make(SafetyService::class)->block($this->user(), $id);
        $this->flash('success', 'Blocked. You will no longer see or hear from each other. You can unblock from Account → Blocked.');
        return $this->redirect('/matches');
    }

    public function unblock(int $id): Response
    {
        $this->app->make(SafetyService::class)->unblock($this->user(), $id);
        $this->flash('success', 'Unblocked.');
        return $this->redirect('/account');
    }

    public function report(): Response
    {
        $this->app->make(SafetyService::class)->report($this->user(), (string)$this->req->input('target_type', ''), (int)$this->req->inputInt('target_id'), (string)$this->req->input('reason', ''), (string)$this->req->input('details', ''), (bool)$this->req->input('block'));
        if ($this->req->header('Accept') && str_contains((string)$this->req->header('Accept'), 'application/json')) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'Thank you. Our moderators review every report — usually within 24 hours. If you are in danger, call 112.');
        return $this->req->input('block') ? $this->redirect('/matches') : $this->back();
    }

    public function verifyPage(): Response
    {
        $svc = $this->app->make(VerificationService::class);
        $u = $this->user();
        $domains = $this->app->db()->fetchAll("SELECT co.name, co.email_domain, c.name AS city_name FROM colleges co JOIN cities c ON c.id = co.city_id WHERE co.active = 1 AND co.email_domain IS NOT NULL AND co.email_domain <> '' ORDER BY c.name, co.name");
        return $this->view('safety/verify', ['title' => 'Verify you are a student', 'verified' => $svc->isVerified($u->id), 'history' => $svc->forUser($u->id), 'domains' => $domains, 'pendingEmail' => $_SESSION['_college_email'] ?? null, 'noindex' => true]);
    }

    public function verifyEmail(): Response
    {
        $this->app->make(VerificationService::class)->startCollegeEmail($this->user(), (string)$this->req->input('college_email', ''));
        $this->flash('success', 'We sent a code to your college email.');
        return $this->redirect('/verify');
    }

    public function confirmEmail(): Response
    {
        $this->app->make(VerificationService::class)->confirmCollegeEmail($this->user(), (string)$this->req->input('code', ''));
        $this->flash('success', 'College email verified ✔');
        return $this->redirect('/verify');
    }

    public function document(): Response
    {
        $this->app->make(VerificationService::class)->submitDocument($this->user(), (string)$this->req->input('document_type', ''), $this->req->file('document'));
        $this->flash('success', 'Document uploaded. A moderator will review it, then it is deleted.');
        return $this->redirect('/verify');
    }
}
