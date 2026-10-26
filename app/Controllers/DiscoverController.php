<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Http\Response;
use Flatmates\Services\MatchService;
use Flatmates\Services\ProfileService;

final class DiscoverController extends Controller
{
    private function matchSvc(): MatchService
    {
        return $this->app->make(MatchService::class);
    }

    private function filters(): array
    {
        $q = $this->req;
        $date = (string)$q->query('move_in_before', '');
        return array_filter([
            'college_id' => $q->queryInt('college_id'),
            'budget_max' => $q->queryInt('budget_max'),
            'move_in_before' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'room_type' => in_array($q->query('room_type'), ['private', 'shared'], true) ? $q->query('room_type') : null,
            'diet' => $q->query('diet') === 'veg' ? 'veg' : null,
            'non_smoker' => $q->query('non_smoker') === '1',
            'verified_only' => $q->query('verified_only') === '1',
            'status' => in_array($q->query('status'), ['looking_room', 'looking_flatmate', 'has_room'], true) ? $q->query('status') : null,
        ]);
    }

    public function index(): Response
    {
        $u = $this->user();
        $problem = $this->matchSvc()->canDiscover($u);
        $me = $this->app->make(ProfileService::class)->get($u->id);
        return $this->view('discover/index', [
            'title' => 'Discover flatmates', 'problem' => $problem, 'filters' => $this->filters(), 'me' => $me,
            'colleges' => $me && $me['city_id'] ? $this->app->make(ProfileService::class)->colleges((int)$me['city_id']) : [],
            'remaining' => max(0, (int)$this->app->settings()->get('rules.daily_likes', 60) - $this->matchSvc()->swipesToday($u->id)),
            'pendingLikes' => $this->matchSvc()->pendingLikes($u->id),
            'minCompleteness' => (int)$this->app->settings()->get('rules.min_completeness', 60),
            'noindex' => true,
        ]);
    }

    public function deck(): Response
    {
        $cards = $this->matchSvc()->deck($this->user(), $this->filters());
        $out = [];
        foreach ($cards as $c) {
            $out[] = [
                'id' => $c['user_id'], 'name' => $c['display_name'], 'photo' => media_url($c['photo_path']), 'initials' => initials($c['display_name']),
                'age' => $c['age'] ?? null, 'college' => $c['college_name'], 'course' => $c['course'] ?? null, 'locality' => $c['locality'],
                'status' => label('accommodation_status', $c['accommodation_status']),
                'budget' => $c['budget_max'] ? ($c['budget_min'] ? inr($c['budget_min']) . '–' : 'up to ') . inr($c['budget_max']) : null,
                'move_in' => $c['move_in_date'] ? fmt_date($c['move_in_date']) : null,
                'bio' => $c['bio'], 'verified' => $c['verified'], 'score' => $c['score'], 'reasons' => $c['reasons'], 'conflicts' => $c['conflicts'],
                'tags' => array_values(array_filter([
                    $c['sleep_schedule'] ? label('sleep_schedule', $c['sleep_schedule']) : null,
                    $c['cleanliness'] ? label('cleanliness', (string)$c['cleanliness']) : null,
                    isset($c['diet']) && $c['diet'] ? label('diet', $c['diet']) : null,
                    isset($c['smoking']) && $c['smoking'] ? label('smoking', $c['smoking']) : null,
                    $c['study_habits'] ? label('study_habits', $c['study_habits']) : null,
                    $c['social_level'] ? label('social_level', $c['social_level']) : null,
                ])),
            ];
        }
        return $this->json(['cards' => $out]);
    }

    public function swipe(): Response
    {
        $r = $this->matchSvc()->swipe($this->user(), (int)$this->req->inputInt('target_id'), (string)$this->req->input('action', ''));
        if ($r['matched']) {
            $r['chat_url'] = url('/chat/' . $r['conversation_id']);
        }
        return $this->json($r);
    }

    public function matches(): Response
    {
        return $this->view('discover/matches', ['title' => 'Your matches', 'matches' => $this->matchSvc()->matches($this->user()), 'pendingLikes' => $this->matchSvc()->pendingLikes($this->user()->id), 'noindex' => true]);
    }

    public function unmatch(int $id): Response
    {
        $this->matchSvc()->unmatch($this->user(), $id);
        $this->flash('success', 'Unmatched. You will not see each other in discovery again.');
        return $this->redirect('/matches');
    }
}
