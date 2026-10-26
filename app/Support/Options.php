<?php
declare(strict_types=1);

namespace Flatmates\Support;

/** Allowed values + human labels for every enumerated field (single source of truth). */
final class Options
{
    public const GROUPS = [
        'gender' => ['female' => 'Female', 'male' => 'Male', 'non_binary' => 'Non-binary', 'prefer_not' => 'Prefer not to say'],
        'flatmate_gender_pref' => ['any' => 'Any gender', 'same' => 'Same gender only'],
        'accommodation_status' => ['looking_room' => 'Looking for a room', 'looking_flatmate' => 'Have a place, looking for flatmates', 'has_room' => 'Have a vacant bed/room', 'not_sure' => 'Just exploring'],
        'diet' => ['veg' => 'Vegetarian', 'non_veg' => 'Non-vegetarian', 'eggetarian' => 'Eggetarian', 'vegan' => 'Vegan', 'any' => 'No preference'],
        'diet_pref' => ['veg_only' => 'Vegetarian kitchen only', 'any' => 'Any kitchen is fine'],
        'smoking' => ['never' => 'Never smoke', 'outside' => 'Smoke outside only', 'yes' => 'Smoke'],
        'alcohol' => ['never' => 'Don\'t drink', 'sometimes' => 'Occasionally', 'often' => 'Regularly'],
        'cleanliness' => ['1' => 'Relaxed', '2' => 'Fairly tidy', '3' => 'Tidy', '4' => 'Very tidy', '5' => 'Spotless'],
        'sleep_schedule' => ['early' => 'Early bird', 'normal' => 'Regular hours', 'late' => 'Night owl'],
        'study_habits' => ['quiet' => 'Needs quiet to study', 'music' => 'Studies with music', 'group' => 'Group study'],
        'social_level' => ['introvert' => 'Keeps to myself', 'balanced' => 'Balanced', 'social' => 'Very social'],
        'guests' => ['rarely' => 'Rarely have guests', 'sometimes' => 'Sometimes', 'often' => 'Often'],
        'ac_pref' => ['needed' => 'AC needed', 'optional' => 'AC optional', 'not_needed' => 'No AC needed'],
        'room_type' => ['private' => 'Private room', 'shared' => 'Shared room', 'any' => 'Either'],
        'listing_type' => ['flat' => 'Entire flat', 'shared_room' => 'Shared room', 'vacant_bed' => 'Vacant bed'],
        'furnishing' => ['unfurnished' => 'Unfurnished', 'semi' => 'Semi-furnished', 'furnished' => 'Fully furnished'],
        'listing_gender' => ['any' => 'Anyone', 'female' => 'Women only', 'male' => 'Men only'],
        'listing_status' => ['draft' => 'Draft', 'pending' => 'Awaiting review', 'published' => 'Live', 'hidden' => 'Hidden by moderator', 'rejected' => 'Rejected', 'closed' => 'Closed'],
        'report_reason' => ['fake' => 'Fake profile or listing', 'scam' => 'Scam or asking for money', 'harassment' => 'Harassment or threats', 'inappropriate' => 'Inappropriate content', 'discrimination' => 'Discrimination', 'safety' => 'Safety concern', 'spam' => 'Spam', 'other' => 'Something else'],
        'document_type' => ['college_id' => 'College ID card', 'admission_letter' => 'Admission / enrolment letter', 'fee_receipt' => 'Fee receipt', 'other' => 'Other student document'],
        'role' => ['student' => 'Student', 'moderator' => 'Moderator', 'super_admin' => 'Super Admin'],
        'status' => ['pending' => 'Email not verified', 'active' => 'Active', 'restricted' => 'Restricted', 'suspended' => 'Suspended', 'deleted' => 'Deleted'],
    ];

    public const AMENITIES = ['wifi' => 'Wi-Fi', 'ac' => 'AC', 'washing_machine' => 'Washing machine', 'fridge' => 'Fridge', 'kitchen' => 'Kitchen', 'geyser' => 'Geyser', 'power_backup' => 'Power backup', 'parking' => 'Parking', 'security' => 'Security / CCTV', 'lift' => 'Lift', 'meals' => 'Meals included', 'housekeeping' => 'Housekeeping', 'attached_bath' => 'Attached bathroom', 'balcony' => 'Balcony', 'wheelchair' => 'Wheelchair accessible'];

    public const LANGUAGES = ['Hindi', 'English', 'Bengali', 'Marathi', 'Telugu', 'Tamil', 'Gujarati', 'Urdu', 'Kannada', 'Odia', 'Malayalam', 'Punjabi', 'Assamese', 'Maithili', 'Other'];

    /** Profile fields the owner can hide from others (default visibility shown in comment). */
    public const PRIVATE_FIELDS = ['gender' => 'Gender', 'age' => 'Age', 'course' => 'Course & year', 'languages' => 'Languages', 'diet' => 'Food', 'smoking' => 'Smoking', 'alcohol' => 'Drinking'];

    /** Fields hidden by default (sensitive / potentially discriminatory). */
    public const HIDDEN_BY_DEFAULT = ['gender', 'age'];

    public static function values(string $group): array
    {
        return array_keys(self::GROUPS[$group] ?? []);
    }

    public static function label(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return self::GROUPS[$group][$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}
