<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Core\ValidationException;
use BharatSeo\Services\OutreachService;

/** Automatic draft generation (drafts still require approval before sending). */
final class GenerateDraftJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $d = $job['data'];
        try {
            $this->app->make(OutreachService::class)->createDraft(null, (int)($d['campaign_id'] ?? 0), (int)($d['prospect_id'] ?? 0), $this->app->settings()->moduleEnabled('local_ai'));
        } catch (ValidationException $e) {
            throw new PermanentException($e->getMessage() . ' ' . implode(' ', $e->errors));
        }
    }
}
