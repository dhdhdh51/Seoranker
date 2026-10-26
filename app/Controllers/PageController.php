<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\HttpException;
use Flatmates\Http\Response;
use Flatmates\Services\ListingService;
use Flatmates\Services\ProfileService;

/** Public marketing, SEO landing pages, legal pages, sitemap and robots.txt. */
final class PageController extends Controller
{
    private function cityStats(int $cityId): array
    {
        $db = $this->app->db();
        return [
            'students' => (int)$db->fetchColumn("SELECT COUNT(*) FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.city_id = ? AND u.status = 'active' AND p.discoverable = 1 AND p.onboarded_at IS NOT NULL", [$cityId]),
            'listings' => (int)$db->fetchColumn("SELECT COUNT(*) FROM listings WHERE city_id = ? AND status = 'published' AND visibility = 'public'", [$cityId]),
            'avg_rent' => (int)$db->fetchColumn("SELECT ROUND(AVG(rent)) FROM listings WHERE city_id = ? AND status = 'published'", [$cityId]),
        ];
    }

    public function home(): Response
    {
        if ($this->currentUser) {
            return $this->redirect($this->currentUser->isStaff() ? '/admin' : '/discover');
        }
        $db = $this->app->db();
        $cities = $db->fetchAll("SELECT c.*, (SELECT COUNT(*) FROM listings l WHERE l.city_id = c.id AND l.status = 'published' AND l.visibility = 'public') AS listings FROM cities c WHERE c.active = 1 ORDER BY c.name");
        $latest = $this->app->make(ListingService::class)->search([], 1, null, 6)['rows'];
        return $this->view('pages/home', [
            'title' => (string)$this->app->settings()->get('seo.home_title'),
            'description' => (string)$this->app->settings()->get('seo.home_description'),
            'canonical' => absolute_url('/'), 'cities' => $cities, 'latest' => $latest, 'bodyClass' => 'home',
        ]);
    }

    public function cities(): Response
    {
        $ps = $this->app->make(ProfileService::class);
        $colleges = [];
        foreach ($ps->colleges() as $c) {
            $colleges[(int)$c['city_id']][] = $c;
        }
        return $this->view('pages/cities', ['title' => 'Student flatmates by city & college', 'description' => 'Find student flatmates and rooms near colleges in every city we cover.', 'cities' => $ps->cities(), 'colleges' => $colleges, 'canonical' => absolute_url('/cities')]);
    }

    public function city(string $city): Response
    {
        $c = $this->app->db()->fetch('SELECT * FROM cities WHERE slug = ? AND active = 1', [$city]);
        if (!$c) {
            throw new HttpException(404);
        }
        $ps = $this->app->make(ProfileService::class);
        $listings = $this->app->make(ListingService::class)->search(['city_id' => (int)$c['id']], 1, null, 9);
        return $this->view('pages/city', [
            'title' => 'Student Flatmates & Rooms in ' . $c['name'] . ' — near colleges',
            'description' => 'Find compatible student flatmates, shared rooms, PG-style vacant beds and flats near colleges in ' . $c['name'] . ', ' . $c['state'] . '. Verified students, private chat, no brokerage.',
            'c' => $c, 'colleges' => $ps->colleges((int)$c['id']), 'stats' => $this->cityStats((int)$c['id']), 'listings' => $listings['rows'],
            'canonical' => absolute_url('/flatmates/' . $c['slug']),
        ]);
    }

    public function college(string $city, string $college): Response
    {
        $row = $this->app->db()->fetch('SELECT co.*, c.name AS city_name, c.slug AS city_slug, c.state FROM colleges co JOIN cities c ON c.id = co.city_id WHERE co.slug = ? AND c.slug = ? AND co.active = 1 AND c.active = 1', [$college, $city]);
        if (!$row) {
            throw new HttpException(404);
        }
        $listings = $this->app->make(ListingService::class)->search(['college_id' => (int)$row['id']], 1, null, 9);
        $nearby = $listings['rows'] ?: $this->app->make(ListingService::class)->search(['city_id' => (int)$row['city_id']], 1, null, 6)['rows'];
        $students = (int)$this->app->db()->fetchColumn("SELECT COUNT(*) FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.college_id = ? AND u.status = 'active' AND p.discoverable = 1 AND p.onboarded_at IS NOT NULL", [$row['id']]);
        return $this->view('pages/college', [
            'title' => 'Flatmates & Rooms near ' . $row['name'] . ', ' . $row['city_name'],
            'description' => 'Looking for a room or flatmate near ' . $row['name'] . '? Match with ' . $row['name'] . ' students by budget and lifestyle, and browse rooms and vacant beds nearby in ' . $row['city_name'] . '.',
            'co' => $row, 'listings' => $listings['rows'], 'nearby' => $listings['rows'] ? [] : $nearby, 'students' => $students,
            'canonical' => absolute_url('/flatmates/' . $row['city_slug'] . '/' . $row['slug']),
        ]);
    }

    public function about(): Response
    {
        return $this->view('pages/about', ['title' => 'About ' . brand(), 'canonical' => absolute_url('/about')]);
    }

    public function safety(): Response
    {
        return $this->view('pages/safety', ['title' => 'Safety guidelines', 'description' => 'How to stay safe when finding a flatmate or room: verification, meeting safely, avoiding rent scams and reporting.', 'canonical' => absolute_url('/safety')]);
    }

    public function terms(): Response
    {
        return $this->view('pages/terms', ['title' => 'Terms of use', 'canonical' => absolute_url('/terms')]);
    }

    public function privacy(): Response
    {
        return $this->view('pages/privacy', ['title' => 'Privacy policy', 'canonical' => absolute_url('/privacy')]);
    }

    public function sitemap(): Response
    {
        $db = $this->app->db();
        $urls = [[absolute_url('/'), null, '1.0'], [absolute_url('/rooms'), null, '0.9'], [absolute_url('/cities'), null, '0.7'], [absolute_url('/safety'), null, '0.4'], [absolute_url('/about'), null, '0.3']];
        foreach ($db->fetchAll('SELECT slug FROM cities WHERE active = 1') as $c) {
            $urls[] = [absolute_url('/flatmates/' . $c['slug']), null, '0.8'];
        }
        foreach ($db->fetchAll('SELECT co.slug, c.slug AS city FROM colleges co JOIN cities c ON c.id = co.city_id WHERE co.active = 1 AND c.active = 1') as $c) {
            $urls[] = [absolute_url('/flatmates/' . $c['city'] . '/' . $c['slug']), null, '0.7'];
        }
        foreach ($db->fetchAll("SELECT l.slug, l.updated_at FROM listings l JOIN users u ON u.id = l.user_id WHERE l.status = 'published' AND l.visibility = 'public' AND u.status = 'active' ORDER BY l.id DESC LIMIT 5000") as $l) {
            $urls[] = [absolute_url('/rooms/' . $l['slug']), substr((string)$l['updated_at'], 0, 10), '0.6'];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $mod, $prio]) {
            $xml .= '  <url><loc>' . e($loc) . '</loc>' . ($mod ? '<lastmod>' . e($mod) . '</lastmod>' : '') . '<priority>' . $prio . "</priority></url>\n";
        }
        $xml .= '</urlset>' . "\n";
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /api/\nDisallow: /chat\nDisallow: /account\nDisallow: /profile\nDisallow: /media/photos/\nDisallow: /onboarding\nDisallow: /discover\nDisallow: /matches\nDisallow: /my/\nDisallow: /sso\nDisallow: /install.php\nAllow: /\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
