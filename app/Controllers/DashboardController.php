<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Http\Response;
use Flatmates\Services\ChatService;
use Flatmates\Services\ListingService;
use Flatmates\Services\MatchService;
use Flatmates\Services\ProfileService;
use Flatmates\Services\TrustService;

/** Student home: progress, trust status, activity and next steps (all real data). */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        $db = $this->app->db();
        $p = $this->app->make(ProfileService::class)->get($u->id);
        $badges = $this->app->make(TrustService::class)->forUser($u->id);
        $pendingDoc = (bool)$db->fetchColumn("SELECT 1 FROM verifications WHERE user_id = ? AND method = 'document' AND status = 'pending'", [$u->id]);
        $matches = $this->app->make(MatchService::class)->matches($u);
        $listings = $this->app->make(ListingService::class)->mine($u);
        $newEnquiries = (int)$db->fetchColumn("SELECT COUNT(*) FROM enquiries WHERE owner_id = ? AND status = 'new'", [$u->id]);
        $saved = (int)$db->fetchColumn('SELECT COUNT(*) FROM saved_items WHERE user_id = ?', [$u->id]);
        $min = (int)$this->app->settings()->get('rules.min_completeness', 60);
        $steps = [];
        if ((int)$p['completeness'] < 100) {
            $steps[] = ['icon' => 'person-lines-fill', 'title' => 'Complete your profile (' . (int)$p['completeness'] . '%)', 'text' => (int)$p['completeness'] < $min ? "Reach $min% so other students can find you in Discover." : 'More details make your compatibility score more accurate.', 'href' => '/onboarding/1', 'cta' => 'Continue'];
        }
        if (!$p['photo_path']) {
            $steps[] = ['icon' => 'camera', 'title' => 'Add a profile photo', 'text' => 'Only signed-in members can see it. Location data is removed automatically.', 'href' => '/onboarding/4', 'cta' => 'Add photo'];
        }
        if (!TrustService::isStudentVerified($badges)) {
            $steps[] = ['icon' => 'patch-check', 'title' => $pendingDoc ? 'Your student ID is being reviewed' : 'Verify you are a student', 'text' => $pendingDoc ? 'A moderator will review your document soon.' : 'Use your college email for instant verification, or upload a student ID.', 'href' => '/verify', 'cta' => $pendingDoc ? 'View status' : 'Verify now'];
        }
        if (!(int)$p['discoverable']) {
            $steps[] = ['icon' => 'eye-slash', 'title' => 'You are hidden from Discover', 'text' => 'Turn discovery back on when you are ready to meet flatmates.', 'href' => '/onboarding/4', 'cta' => 'Privacy settings'];
        }
        return $this->view('dashboard/index', [
            'title' => 'Home', 'noindex' => true, 'p' => $p, 'badges' => $badges, 'pendingDoc' => $pendingDoc, 'steps' => $steps,
            'matches' => array_slice($matches, 0, 6), 'matchCount' => count($matches), 'pendingLikes' => $this->app->make(MatchService::class)->pendingLikes($u->id),
            'unread' => $this->app->make(ChatService::class)->unreadTotal($u->id), 'listings' => array_slice($listings, 0, 3), 'listingCount' => count($listings),
            'newEnquiries' => $newEnquiries, 'saved' => $saved,
            'notifications' => $db->fetchAll('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$u->id]),
            'rooms' => $p['city_id'] ? $this->app->make(ListingService::class)->search(['city_id' => (int)$p['city_id']], 1, $u, 3)['rows'] : [],
        ]);
    }
}
