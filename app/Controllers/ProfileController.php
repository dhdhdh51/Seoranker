<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\HttpException;
use Flatmates\Http\Response;
use Flatmates\Services\ListingService;
use Flatmates\Services\MatchService;
use Flatmates\Services\ProfileService;
use Flatmates\Services\VerificationService;

final class ProfileController extends Controller
{
    private function profiles(): ProfileService
    {
        return $this->app->make(ProfileService::class);
    }

    public function step(int $step): Response
    {
        $p = $this->profiles()->ensure($this->user());
        if (!$p['onboarded_at'] && $step > max(1, (int)$p['onboarding_step'])) {
            return $this->redirect('/onboarding/' . max(1, (int)$p['onboarding_step']));
        }
        return $this->view('profile/onboarding', [
            'title' => 'Your profile — ' . ProfileService::STEPS[$step],
            'step' => $step, 'p' => $p, 'editing' => (bool)$p['onboarded_at'],
            'cities' => $this->profiles()->cities(), 'colleges' => $this->profiles()->colleges(),
            'noindex' => true,
        ]);
    }

    public function saveStep(int $step): Response
    {
        $this->profiles()->saveStep($this->user(), $step, $this->req->all(), $this->req->file('photo'));
        $p = $this->profiles()->get($this->user()->id);
        if ($step < 4 && !($this->req->input('save_only'))) {
            return $this->redirect('/onboarding/' . ($step + 1));
        }
        if ($step === 4 && (int)$p['completeness'] < (int)$this->app->settings()->get('rules.min_completeness', 60)) {
            $this->flash('warning', 'Profile saved. Fill in a few more details (' . $p['completeness'] . '% complete) to appear in other students\' discovery.');
        } else {
            $this->flash('success', 'Profile saved.');
        }
        return $this->redirect($step === 4 && !$this->req->input('save_only') ? '/discover' : '/profile');
    }

    public function me(): Response
    {
        $u = $this->user();
        $view = $this->profiles()->viewFor($u, $u->id);
        $raw = $this->profiles()->get($u->id);
        return $this->view('profile/show', ['title' => 'My profile', 'profile' => $view, 'raw' => $raw, 'self' => true, 'verifications' => $this->app->make(VerificationService::class)->forUser($u->id), 'noindex' => true]);
    }

    public function show(int $id): Response
    {
        $u = $this->user();
        if ($id === $u->id) {
            return $this->redirect('/profile');
        }
        $view = $this->profiles()->viewFor($u, $id);
        if (!$view) {
            throw new HttpException(404, 'This profile is private or no longer available.');
        }
        $match = $this->app->make(MatchService::class)->activeMatch($u->id, $id);
        $compat = null;
        if ($u->isStudent()) {
            $me = $this->profiles()->get($u->id);
            $other = $this->profiles()->get($id);
            $compat = $me && $other ? MatchService::compatibility($me, $other) : null;
        }
        $conv = $match ? $this->app->db()->fetchColumn('SELECT id FROM conversations WHERE match_id = ?', [$match['id']]) : null;
        return $this->view('profile/show', ['title' => $view['display_name'], 'profile' => $view, 'self' => false, 'match' => $match, 'conversationId' => $conv, 'compat' => $compat,
            'saved' => $this->app->make(ListingService::class)->isSaved($u->id, 'profile', $id), 'noindex' => true]);
    }

    public function colleges(): Response
    {
        $city = $this->req->queryInt('city');
        $rows = $this->profiles()->colleges($city ?: null);
        return $this->json(array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'city_id' => (int)$c['city_id']], $rows));
    }
}
