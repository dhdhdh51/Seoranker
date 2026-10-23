<?php
declare(strict_types=1);

namespace BharatSeo\Support;

/** Human labels and badge styles for enums used across the UI and reports. */
final class Labels
{
    public const CATEGORIES = [
        'business_directory' => 'Business directory',
        'local_citation' => 'Local citation',
        'industry_association' => 'Industry association',
        'resource_page' => 'Resource page',
        'guest_post' => 'Editorial guest post',
        'expert_contribution' => 'Expert contribution / interview',
        'broken_link' => 'Broken-link replacement',
        'unlinked_mention' => 'Unlinked brand mention',
        'partner_page' => 'Supplier / partner / customer page',
        'community_profile' => 'Community / professional profile',
        'other' => 'Other',
    ];

    public const SOURCES = [
        'manual' => 'Manual entry',
        'csv' => 'CSV import',
        'dataset' => 'Public dataset',
        'competitor_export' => 'Competitor backlink export',
        'search_api' => 'Search API',
        'public_page' => 'Public page links',
        'prospect_list' => 'Research-tool prospect list',
    ];

    public const PROSPECT_STATUSES = [
        'discovered' => 'Discovered',
        'evaluated' => 'Evaluated',
        'needs_review' => 'Needs review',
        'approved' => 'Approved',
        'outreach_sent' => 'Outreach sent',
        'submitted' => 'Submitted',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'failed' => 'Failed',
    ];

    public const BACKLINK_STATUSES = [
        'unverified' => 'Unverified',
        'active' => 'Active',
        'lost' => 'Lost',
        'redirected' => 'Redirected',
        'broken' => 'Broken',
        'not_pointing' => 'No longer pointing to target',
        'changed_anchor' => 'Changed anchor',
        'nofollow' => 'Nofollow / Sponsored / UGC',
        'temporarily_unavailable' => 'Temporarily unavailable',
    ];

    public const LIFECYCLE = [
        'candidate' => 'Candidate (not yet observed)',
        'confirmed' => 'Backlink confirmed',
        'lost' => 'Backlink lost',
    ];

    public const MESSAGE_STATUSES = [
        'draft' => 'Draft',
        'needs_review' => 'Awaiting approval',
        'approved' => 'Approved (queued to send)',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'suppressed' => 'Suppressed',
    ];

    public const CAMPAIGN_TYPES = [
        'guest_post' => 'Editorial contribution pitch',
        'resource_page' => 'Resource page suggestion',
        'broken_link' => 'Broken-link replacement',
        'unlinked_mention' => 'Unlinked brand mention',
        'expert_contribution' => 'Expert contribution / interview',
        'partner_page' => 'Supplier / partner page',
        'general' => 'General collaboration',
    ];

    /** @return array{0:string,1:string} [css class, label] */
    public static function badge(string $status): array
    {
        $label = self::PROSPECT_STATUSES[$status] ?? self::BACKLINK_STATUSES[$status] ?? self::MESSAGE_STATUSES[$status] ?? self::LIFECYCLE[$status] ?? ucwords(str_replace('_', ' ', $status));
        $cls = match ($status) {
            'active', 'verified', 'approved', 'sent', 'completed', 'confirmed', 'high', 'ok', 'positive' => 'text-bg-success',
            'needs_review', 'pending', 'review', 'changed_anchor', 'redirected', 'temporarily_unavailable', 'warning', 'running', 'queued', 'processing', 'sending', 'replied' => 'text-bg-warning',
            'lost', 'broken', 'rejected', 'failed', 'critical', 'suppressed', 'not_pointing', 'negative', 'bounced', 'disabled' => 'text-bg-danger',
            'nofollow', 'evaluated', 'outreach_sent', 'submitted', 'info' => 'text-bg-info',
            'low', 'cancelled', 'draft', 'archived', 'paused', 'inactive', 'unverified', 'candidate', 'discovered', 'none', 'skipped' => 'text-bg-secondary',
            default => 'text-bg-light border',
        };
        return [$cls, $label];
    }

    public static function category(string $c): string
    {
        return self::CATEGORIES[$c] ?? ucwords(str_replace('_', ' ', $c));
    }
}
