<?php
declare(strict_types=1);

use Flatmates\Controllers\AccountController;
use Flatmates\Controllers\Admin\AdminController;
use Flatmates\Controllers\AuthController;
use Flatmates\Controllers\AppController;
use Flatmates\Controllers\ChatController;
use Flatmates\Controllers\DashboardController;
use Flatmates\Controllers\DiscoverController;
use Flatmates\Controllers\ListingController;
use Flatmates\Controllers\MediaController;
use Flatmates\Controllers\PageController;
use Flatmates\Controllers\ProfileController;
use Flatmates\Controllers\SafetyController;
use Flatmates\Http\Router;
use Flatmates\Security\User;

return static function (Router $r): void {
    $guest = ['auth' => false];
    $member = ['auth' => true];
    $student = ['auth' => true, 'roles' => [User::STUDENT], 'onboarded' => true];
    $staff = ['auth' => true, 'roles' => [User::MODERATOR, User::SUPER_ADMIN]];
    $admin = ['auth' => true, 'roles' => [User::SUPER_ADMIN]];

    // Public & SEO pages
    $r->get('/', [PageController::class, 'home'], $guest);
    $r->get('/cities', [PageController::class, 'cities'], $guest);
    $r->get('/flatmates/{city}', [PageController::class, 'city'], $guest);
    $r->get('/flatmates/{city}/{college}', [PageController::class, 'college'], $guest);
    $r->get('/about', [PageController::class, 'about'], $guest);
    $r->get('/safety', [PageController::class, 'safety'], $guest);
    $r->get('/terms', [PageController::class, 'terms'], $guest);
    $r->get('/privacy', [PageController::class, 'privacy'], $guest);
    $r->get('/sitemap.xml', [PageController::class, 'sitemap'], $guest + ['session' => false]);
    $r->get('/robots.txt', [PageController::class, 'robots'], $guest + ['session' => false]);
    $r->get('/media/{dir}/{file}', [MediaController::class, 'show'], $guest);
    $r->get('/app', [AppController::class, 'show'], $guest);
    $r->get('/app/qr.svg', [AppController::class, 'qr'], $guest + ['session' => false]);
    $r->get('/.well-known/assetlinks.json', [AppController::class, 'assetLinks'], $guest + ['session' => false]);

    // Authentication
    $r->get('/register', [AuthController::class, 'registerForm'], $guest);
    $r->post('/register', [AuthController::class, 'register'], $guest);
    $r->get('/verify-email', [AuthController::class, 'verifyForm'], $guest);
    $r->post('/verify-email', [AuthController::class, 'verify'], $guest);
    $r->post('/verify-email/resend', [AuthController::class, 'resend'], $guest);
    $r->get('/login', [AuthController::class, 'loginForm'], $guest);
    $r->post('/login', [AuthController::class, 'login'], $guest);
    $r->post('/logout', [AuthController::class, 'logout'], $member + ['verified' => false]);
    $r->get('/forgot-password', [AuthController::class, 'forgotForm'], $guest);
    $r->post('/forgot-password', [AuthController::class, 'forgot'], $guest);
    $r->get('/reset-password', [AuthController::class, 'resetForm'], $guest);
    $r->post('/reset-password', [AuthController::class, 'reset'], $guest);
    $r->get('/sso', [AuthController::class, 'sso'], $guest);

    // Listings (public browse; posting needs an account)
    $r->get('/rooms', [ListingController::class, 'index'], $guest);
    $r->get('/rooms/{slug}', [ListingController::class, 'show'], $guest);
    $r->post('/rooms/{id}/enquire', [ListingController::class, 'enquire'], $student);
    $r->get('/my/listings', [ListingController::class, 'mine'], $student);
    $r->get('/my/listings/new', [ListingController::class, 'create'], $student);
    $r->post('/my/listings', [ListingController::class, 'store'], $student);
    $r->get('/my/listings/{id}/edit', [ListingController::class, 'edit'], $student);
    $r->post('/my/listings/{id}', [ListingController::class, 'update'], $student);
    $r->post('/my/listings/{id}/photos', [ListingController::class, 'addPhotos'], $student);
    $r->post('/my/listings/{id}/photos/{pid}/delete', [ListingController::class, 'deletePhoto'], $student);
    $r->post('/my/listings/{id}/submit', [ListingController::class, 'submit'], $student);
    $r->post('/my/listings/{id}/close', [ListingController::class, 'close'], $student);
    $r->post('/my/listings/{id}/delete', [ListingController::class, 'destroy'], $student);
    $r->get('/enquiries', [ListingController::class, 'enquiries'], $student);
    $r->post('/enquiries/{id}/respond', [ListingController::class, 'respond'], $student);
    $r->post('/enquiries/{id}/cancel', [ListingController::class, 'cancelEnquiry'], $student);
    $r->get('/saved', [ListingController::class, 'saved'], $student);
    $r->post('/api/save', [ListingController::class, 'toggleSave'], $student);

    // Profile & onboarding
    $r->get('/onboarding/{step}', [ProfileController::class, 'step'], ['auth' => true, 'roles' => [User::STUDENT]]);
    $r->post('/onboarding/{step}', [ProfileController::class, 'saveStep'], ['auth' => true, 'roles' => [User::STUDENT]]);
    $r->get('/profile', [ProfileController::class, 'me'], $student);
    $r->get('/profile/{id}', [ProfileController::class, 'show'], $member);
    $r->get('/colleges.json', [ProfileController::class, 'colleges'], $guest);

    $r->get('/dashboard', [DashboardController::class, 'index'], $student);

    // Discovery, matches, chat
    $r->get('/discover', [DiscoverController::class, 'index'], $student);
    $r->get('/api/deck', [DiscoverController::class, 'deck'], $student);
    $r->post('/api/swipe', [DiscoverController::class, 'swipe'], $student);
    $r->get('/matches', [DiscoverController::class, 'matches'], $student);
    $r->post('/matches/{id}/unmatch', [DiscoverController::class, 'unmatch'], $student);
    $r->get('/chat', [ChatController::class, 'inbox'], $student);
    $r->get('/chat/{id}', [ChatController::class, 'show'], $student);
    $r->get('/api/chat/{id}', [ChatController::class, 'messages'], $student);
    $r->post('/api/chat/{id}', [ChatController::class, 'send'], $student);
    $r->get('/api/poll', [ChatController::class, 'poll'], $member);

    // Safety & verification
    $r->post('/block/{id}', [SafetyController::class, 'block'], $student);
    $r->post('/unblock/{id}', [SafetyController::class, 'unblock'], $member);
    $r->post('/report', [SafetyController::class, 'report'], $member);
    $r->get('/verify', [SafetyController::class, 'verifyPage'], ['auth' => true, 'roles' => [User::STUDENT]]);
    $r->post('/verify/email', [SafetyController::class, 'verifyEmail'], ['auth' => true, 'roles' => [User::STUDENT]]);
    $r->post('/verify/email/confirm', [SafetyController::class, 'confirmEmail'], ['auth' => true, 'roles' => [User::STUDENT]]);
    $r->post('/verify/document', [SafetyController::class, 'document'], ['auth' => true, 'roles' => [User::STUDENT]]);

    // Account
    $r->get('/notifications', [AccountController::class, 'notifications'], $member);
    $r->post('/notifications/read', [AccountController::class, 'readNotifications'], $member);
    $r->get('/account', [AccountController::class, 'index'], $member);
    $r->post('/account/password', [AccountController::class, 'password'], $member);
    $r->get('/account/export', [AccountController::class, 'export'], $member);
    $r->post('/account/delete', [AccountController::class, 'delete'], $member);
    $r->post('/account/delete/cancel', [AccountController::class, 'cancelDelete'], $member);

    // Admin
    $r->get('/admin', [AdminController::class, 'dashboard'], $staff);
    $r->get('/admin/reports', [AdminController::class, 'reports'], $staff + ['perm' => 'reports']);
    $r->get('/admin/reports/{id}', [AdminController::class, 'report'], $staff + ['perm' => 'reports']);
    $r->post('/admin/reports/{id}', [AdminController::class, 'decideReport'], $staff + ['perm' => 'reports']);
    $r->get('/admin/verifications', [AdminController::class, 'verifications'], $staff + ['perm' => 'verifications']);
    $r->get('/admin/verifications/{id}', [AdminController::class, 'verification'], $staff + ['perm' => 'verifications']);
    $r->get('/admin/verifications/{id}/document', [AdminController::class, 'document'], $staff + ['perm' => 'verifications']);
    $r->post('/admin/verifications/{id}', [AdminController::class, 'reviewVerification'], $staff + ['perm' => 'verifications']);
    $r->get('/admin/listings', [AdminController::class, 'listings'], $staff + ['perm' => 'listings']);
    $r->post('/admin/listings/{id}', [AdminController::class, 'moderateListing'], $staff + ['perm' => 'listings']);
    $r->post('/admin/photos/{id}/delete', [AdminController::class, 'deletePhoto'], $staff + ['perm' => 'listings']);
    $r->get('/admin/users', [AdminController::class, 'users'], $staff + ['perm' => 'users']);
    $r->get('/admin/users/{id}', [AdminController::class, 'showUser'], $staff + ['perm' => 'users']);
    $r->post('/admin/users/{id}/status', [AdminController::class, 'userStatus'], $staff + ['perm' => 'users']);
    $r->post('/admin/users/{id}/role', [AdminController::class, 'userRole'], $admin);
    $r->post('/admin/staff', [AdminController::class, 'createStaff'], $admin);
    $r->get('/admin/locations', [AdminController::class, 'locations'], $admin);
    $r->post('/admin/locations', [AdminController::class, 'saveLocation'], $admin);
    $r->get('/admin/settings', [AdminController::class, 'settings'], $admin);
    $r->post('/admin/settings', [AdminController::class, 'saveSettings'], $admin);
    $r->post('/admin/settings/test-email', [AdminController::class, 'testEmail'], $admin);
    $r->get('/admin/analytics', [AdminController::class, 'analytics'], $admin);
    $r->get('/admin/audit', [AdminController::class, 'audit'], $admin);
    $r->get('/admin/health', [AdminController::class, 'health'], $admin);
};
