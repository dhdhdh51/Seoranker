<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\HttpException;
use Flatmates\Http\Response;
use Flatmates\Services\EnquiryService;
use Flatmates\Services\ListingService;
use Flatmates\Services\ProfileService;
use Flatmates\Support\Paginator;

final class ListingController extends Controller
{
    private function listings(): ListingService
    {
        return $this->app->make(ListingService::class);
    }

    public static function filtersFrom(array $q): array
    {
        $f = [];
        foreach (['city_id', 'college_id', 'min_rent', 'max_rent'] as $k) {
            if (isset($q[$k]) && is_string($q[$k]) && preg_match('/^\d{1,7}$/', $q[$k])) {
                $f[$k] = (int)$q[$k];
            }
        }
        foreach (['type', 'gender', 'furnishing', 'sort', 'available_by', 'q'] as $k) {
            if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '') {
                $f[$k] = mb_substr($q[$k], 0, 80);
            }
        }
        if (isset($q['amenities']) && is_array($q['amenities'])) {
            $f['amenities'] = array_values(array_filter($q['amenities'], 'is_string'));
        }
        return $f;
    }

    public function index(): Response
    {
        $f = self::filtersFrom($_GET);
        $r = $this->listings()->search($f, $this->page(), $this->currentUser);
        $ps = $this->app->make(ProfileService::class);
        $city = !empty($f['city_id']) ? $this->app->db()->fetch('SELECT * FROM cities WHERE id = ?', [$f['city_id']]) : null;
        return $this->view('listings/index', [
            'title' => ($city ? 'Rooms & flats for students in ' . $city['name'] : 'Student rooms, shared flats & vacant beds'),
            'description' => 'Browse verified student flat, shared room and vacant bed listings near colleges' . ($city ? ' in ' . $city['name'] : ' across India') . '.',
            'filters' => $f, 'rows' => $r['rows'], 'pager' => new Paginator($r['total'], $this->page(), ListingService::PER_PAGE), 'total' => $r['total'],
            'cities' => $ps->cities(), 'colleges' => !empty($f['city_id']) ? $ps->colleges((int)$f['city_id']) : [],
            'canonical' => absolute_url('/rooms', array_intersect_key($f, ['city_id' => 1, 'type' => 1])),
            'noindex' => count(array_diff_key($f, ['city_id' => 1, 'type' => 1])) > 0 || $this->page() > 1,
        ]);
    }

    public function show(string $slug): Response
    {
        $l = $this->listings()->view($slug, $this->currentUser);
        $u = $this->currentUser;
        $myEnquiry = $u ? $this->app->db()->fetch('SELECT * FROM enquiries WHERE listing_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1', [$l['id'], $u->id]) : null;
        return $this->view('listings/show', [
            'title' => $l['title'] . ' — ' . $l['locality'] . ', ' . $l['city_name'],
            'description' => str_limit(label('listing_type', $l['type']) . ' for ' . inr($l['rent']) . '/month in ' . $l['locality'] . ', ' . $l['city_name'] . '. ' . preg_replace('/\s+/', ' ', $l['description']), 158),
            'l' => $l, 'myEnquiry' => $myEnquiry, 'saved' => $u ? $this->listings()->isSaved($u->id, 'listing', (int)$l['id']) : false,
            'canonical' => absolute_url('/rooms/' . $l['slug']), 'noindex' => $l['status'] !== 'published' || $l['visibility'] !== 'public',
            'ogImage' => $l['photos'] ? absolute_url('/media/' . $l['photos'][0]['path']) : null,
        ]);
    }

    public function enquire(int $id): Response
    {
        $this->app->make(EnquiryService::class)->create($this->user(), $id, (string)$this->req->input('kind', 'enquiry'), (string)$this->req->input('message', ''), $this->req->input('viewing_at') ? (string)$this->req->input('viewing_at') : null);
        $this->flash('success', 'Sent! The owner will be notified. You can chat once they accept.');
        return $this->back();
    }

    public function mine(): Response
    {
        return $this->view('listings/mine', ['title' => 'My listings', 'rows' => $this->listings()->mine($this->user()), 'noindex' => true]);
    }

    private function formData(?array $l): array
    {
        $ps = $this->app->make(ProfileService::class);
        return ['l' => $l, 'cities' => $ps->cities(), 'colleges' => $ps->colleges(), 'photos' => $l ? $this->listings()->photos((int)$l['id']) : [], 'noindex' => true];
    }

    public function create(): Response
    {
        return $this->view('listings/form', ['title' => 'Post a room'] + $this->formData(null));
    }

    public function store(): Response
    {
        $u = $this->user();
        $id = $this->listings()->create($u, $this->req->all(), (bool)$this->req->input('submit_review'));
        $files = ListingService::normaliseFiles($this->req->file('photos'));
        if ($files) {
            $this->listings()->addPhotos($u, $id, $files);
        }
        $this->flash('success', $this->req->input('submit_review') ? ($this->app->settings()->bool('rules.listing_review') ? 'Listing submitted — a moderator will review it shortly.' : 'Your listing is live!') : 'Draft saved.');
        return $this->redirect('/my/listings/' . $id . '/edit');
    }

    public function edit(int $id): Response
    {
        $l = $this->listings()->ownListing($this->user(), $id);
        return $this->view('listings/form', ['title' => 'Edit listing'] + $this->formData($l));
    }

    public function update(int $id): Response
    {
        $this->listings()->update($this->user(), $id, $this->req->all(), (bool)$this->req->input('submit_review'));
        $this->flash('success', 'Listing saved.');
        return $this->redirect('/my/listings/' . $id . '/edit');
    }

    public function addPhotos(int $id): Response
    {
        $n = $this->listings()->addPhotos($this->user(), $id, ListingService::normaliseFiles($this->req->file('photos')));
        $this->flash($n ? 'success' : 'warning', $n ? "$n photo(s) added." : 'Choose at least one photo.');
        return $this->redirect('/my/listings/' . $id . '/edit');
    }

    public function deletePhoto(int $id, int $pid): Response
    {
        $this->listings()->deletePhoto($this->user(), $id, $pid);
        return $this->redirect('/my/listings/' . $id . '/edit');
    }

    public function submit(int $id): Response
    {
        $this->listings()->submit($this->user(), $id);
        $this->flash('success', $this->app->settings()->bool('rules.listing_review') ? 'Submitted for review.' : 'Your listing is live!');
        return $this->redirect('/my/listings');
    }

    public function close(int $id): Response
    {
        $this->listings()->close($this->user(), $id);
        $this->flash('success', 'Listing closed.');
        return $this->redirect('/my/listings');
    }

    public function destroy(int $id): Response
    {
        $this->listings()->delete($this->user(), $id);
        $this->flash('success', 'Listing deleted.');
        return $this->redirect('/my/listings');
    }

    public function enquiries(): Response
    {
        $svc = $this->app->make(EnquiryService::class);
        return $this->view('listings/enquiries', ['title' => 'Enquiries', 'tab' => $this->req->query('tab') === 'sent' ? 'sent' : 'received', 'received' => $svc->received($this->user()), 'sent' => $svc->sent($this->user()), 'noindex' => true]);
    }

    public function respond(int $id): Response
    {
        $conv = $this->app->make(EnquiryService::class)->respond($this->user(), $id, (string)$this->req->input('decision', ''));
        if ($conv) {
            return $this->redirect('/chat/' . $conv);
        }
        $this->flash('success', 'Enquiry declined.');
        return $this->redirect('/enquiries');
    }

    public function cancelEnquiry(int $id): Response
    {
        $this->app->make(EnquiryService::class)->cancel($this->user(), $id);
        $this->flash('success', 'Enquiry cancelled.');
        return $this->redirect('/enquiries', ['tab' => 'sent']);
    }

    public function saved(): Response
    {
        return $this->view('listings/saved', ['title' => 'Saved', 'saved' => $this->listings()->saved($this->user()), 'noindex' => true]);
    }

    public function toggleSave(): Response
    {
        $type = (string)$this->req->input('type', '');
        $id = (int)$this->req->inputInt('id');
        if (!$id) {
            throw new HttpException(400);
        }
        return $this->json(['saved' => $this->listings()->toggleSave($this->user(), $type, $id)]);
    }
}
