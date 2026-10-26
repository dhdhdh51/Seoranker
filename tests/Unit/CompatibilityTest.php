<?php
declare(strict_types=1);

namespace Flatmates\Tests\Unit;

use Flatmates\Services\ListingService;
use Flatmates\Services\MatchService;
use Flatmates\Services\ProfileService;
use Flatmates\Tests\TestCase;

final class CompatibilityTest extends TestCase
{
    private function base(array $o = []): array
    {
        return $o + ['budget_min' => 6000, 'budget_max' => 10000, 'college_id' => 1, 'locality' => 'Sector 62', 'move_in_date' => '2026-07-01', 'cleanliness' => 4,
            'sleep_schedule' => 'normal', 'smoking' => 'never', 'smoking_ok' => 0, 'alcohol' => 'never', 'alcohol_ok' => 1, 'diet' => 'veg', 'diet_pref' => null,
            'study_habits' => 'quiet', 'social_level' => 'balanced', 'guests' => 'sometimes', 'room_type' => 'any', 'ac_pref' => 'optional', 'accommodation_status' => 'looking_room'];
    }

    public function testIdenticalProfilesScoreHighWithReasons(): void
    {
        $r = MatchService::compatibility($this->base(), $this->base());
        $this->assertGreaterOrEqual(95, $r['score']);
        $this->assertTrue(in_array('Similar budget', $r['reasons'], true));
        $this->assertSame([], $r['conflicts']);
    }

    public function testSmokingClashIsAConflictAndCapsScore(): void
    {
        $r = MatchService::compatibility($this->base(), $this->base(['smoking' => 'yes', 'smoking_ok' => 1]));
        $this->assertTrue(in_array('Smoking preferences clash', $r['conflicts'], true));
        $this->assertLess(86, $r['score']);
    }

    public function testVegOnlyKitchenVsNonVegConflict(): void
    {
        $r = MatchService::compatibility($this->base(['diet_pref' => 'veg_only']), $this->base(['diet' => 'non_veg']));
        $this->assertTrue(in_array('Kitchen preferences clash (veg-only kitchen)', $r['conflicts'], true));
    }

    public function testFarBudgetsLowerScore(): void
    {
        $near = MatchService::compatibility($this->base(), $this->base())['score'];
        $far = MatchService::compatibility($this->base(), $this->base(['budget_min' => 25000, 'budget_max' => 40000]));
        $this->assertLess($near, $far['score']);
        $this->assertTrue(in_array('Budgets are quite different', $far['conflicts'], true));
    }

    public function testMissingDataIsNeutralNotZero(): void
    {
        $r = MatchService::compatibility(['budget_max' => 9000], ['cleanliness' => 2]);
        $this->assertSame(50, $r['score']);
        $sparse = MatchService::compatibility(['cleanliness' => 5], ['cleanliness' => 5]);
        $this->assertGreaterOrEqual(60, $sparse['score']);
        $this->assertLess(100, $sparse['score']);
    }

    public function testScoreIsSymmetricAndBounded(): void
    {
        $a = $this->base(['cleanliness' => 1, 'sleep_schedule' => 'early']);
        $b = $this->base(['cleanliness' => 5, 'sleep_schedule' => 'late', 'guests' => 'often']);
        $x = MatchService::compatibility($a, $b)['score'];
        $this->assertSame($x, MatchService::compatibility($b, $a)['score']);
        $this->assertTrue($x >= 0 && $x <= 100);
    }

    public function testCompletenessAndVisibilityDefaults(): void
    {
        $this->assertSame(0, ProfileService::completeness([]));
        $this->assertTrue(ProfileService::completeness($this->base(['display_name' => 'A', 'city_id' => 1, 'bio' => 'x'])) > 50);
        $this->assertFalse(ProfileService::isVisible([], 'gender'), 'gender hidden by default');
        $this->assertFalse(ProfileService::isVisible([], 'age'), 'age hidden by default');
        $this->assertTrue(ProfileService::isVisible([], 'diet'));
        $this->assertTrue(ProfileService::isVisible(['field_visibility' => '{"gender":true}'], 'gender'));
    }

    public function testPresentHidesPrivateFields(): void
    {
        $row = $this->base(['user_id' => 5, 'display_name' => 'Asha', 'photo_path' => null, 'gender' => 'female', 'date_of_birth' => '2004-01-01', 'course' => 'BBA', 'academic_year' => 2,
            'languages' => 'Hindi', 'flatmates_count' => 1, 'bio' => 'hi', 'completeness' => 80, 'field_visibility' => json_encode(['gender' => false, 'age' => false, 'diet' => false])]);
        $p = ProfileService::present($row, false);
        $this->assertFalse(array_key_exists('gender', $p));
        $this->assertFalse(array_key_exists('age', $p));
        $this->assertFalse(array_key_exists('diet', $p));
        $this->assertSame('BBA', $p['course']);
        $this->assertFalse(array_key_exists('date_of_birth', $p));
        $self = ProfileService::present($row, true);
        $this->assertSame('female', $self['gender']);
    }

    public function testSlugify(): void
    {
        $this->assertSame('room-near-amity-sector-125', ListingService::slugify('Room near Amity — Sector 125!'));
        $this->assertSame('listing', ListingService::slugify('###'));
    }
}
