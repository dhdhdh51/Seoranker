<?php
declare(strict_types=1);

namespace BharatSeo\Services\Outreach;

/**
 * Offline, template-based outreach writer (the default "AI"). Uses only facts
 * we actually have: page title, site name, niche, recorded evidence and project
 * details. It never invents recipient names, past relationships, approvals or metrics.
 */
final class OutreachWriter
{
    /**
     * @param array $ctx keys: prospect, project, target, keywords, topics, sender, follow_up_number, original_subject, original_sent_at
     * @return array{subject:string, alternatives:string[], body:string, generator:string, note:?string}
     */
    public function draft(string $type, array $ctx): array
    {
        $p = $ctx['prospect'];
        $proj = $ctx['project'];
        $site = self::siteName($p);
        $brand = trim((string)($proj['brand_name'] ?? '')) ?: (string)$proj['domain'];
        $niche = trim((string)($proj['niche'] ?? '')) ?: 'your topic';
        $pageTitle = trim((string)($p['title'] ?? ''));
        $pageRef = $pageTitle !== '' ? '“' . self::short($pageTitle, 90) . '”' : 'your page';
        $target = $ctx['target'] ?? null;
        $targetUrl = (string)($target['url'] ?? $proj['website_url']);
        $targetTitle = trim((string)($target['title'] ?? '')) ?: 'our page';
        $sender = trim((string)($ctx['sender'] ?? '')) ?: $brand . ' team';
        $topics = array_values(array_filter(array_merge((array)($ctx['topics'] ?? []), (array)($ctx['keywords'] ?? []))));
        $seed = (int)($p['id'] ?? 0);
        $evidence = (string)($p['evidence'] ?? '');
        $note = null;

        $greeting = self::greeting($p, $site);
        $closing = "Thank you for your time.\n\nBest regards,\n" . self::signature($proj, $sender, $brand);

        if ($type === 'follow_up') {
            $when = !empty($ctx['original_sent_at']) ? ' on ' . date('j M Y', strtotime($ctx['original_sent_at'] . ' UTC')) : '';
            $body = "$greeting\n\nI wanted to briefly follow up on my earlier email$when about $pageRef. I understand inboxes get busy, so no worries if it isn't a fit.\n\nIf it would be useful, I'm happy to share more details. If you'd rather not receive further emails, just let me know and I won't follow up again.\n\n$closing";
            $subjectBase = (string)($ctx['original_subject'] ?? 'my earlier note');
            $subjects = ['Re: ' . $subjectBase, 'Following up: ' . $subjectBase, 'Quick follow-up about ' . $pageRef];
            return self::result($subjects, $body, $seed, null);
        }

        switch ($type) {
            case 'guest_post':
            case 'expert_contribution':
                $ideas = self::topicIdeas($topics, $niche, $seed);
                $guidelines = preg_match('/guidelines|write for us|contribut/i', $evidence . ' ' . ($p['submission_url'] ?? '')) ? ' and saw that you welcome contributions' : '';
                if ($type === 'expert_contribution') {
                    $intro = "I'm $sender from $brand. We work in $niche, and I'd be glad to contribute an expert comment, quote or interview answers for an upcoming piece on $site if that would help your readers.";
                    $offer = "Some areas where we could offer practical, first-hand input:\n" . implode("\n", array_map(static fn($i) => '- ' . $i, $ideas));
                } else {
                    $intro = "I'm $sender from $brand. I'd like to pitch an original, non-promotional article written specifically for $site's readers.";
                    $offer = "A few topic ideas:\n" . implode("\n", array_map(static fn($i) => '- ' . $i, $ideas)) . "\n\nThe piece would follow your editorial guidelines, cite reputable sources and only reference our own site where it genuinely helps the reader — entirely at your editorial discretion.";
                }
                $body = "$greeting\n\nI came across $pageRef on $site$guidelines.\n\n$intro\n\n$offer\n\nIf any of these fit, I'm happy to send an outline first.\n\n$closing";
                $subjects = $type === 'expert_contribution'
                    ? ['Expert input for ' . $site . ' on ' . $niche, 'Offer: ' . $niche . ' expert comment for your readers', 'Contribution idea for ' . $site]
                    : ['Article idea for ' . $site, 'Guest contribution pitch: ' . $ideas[0], 'Original ' . $niche . ' article for your readers', 'Contributor pitch for ' . $site];
                if ($guidelines === '') {
                    $note = 'No contribution guidelines recorded — confirm the site accepts contributions before sending.';
                }
                break;

            case 'resource_page':
                $body = "$greeting\n\nI found $pageRef" . (!empty($p['normalized_url']) ? ' (' . $p['normalized_url'] . ')' : '') . " while researching $niche resources. It's a helpful collection.\n\n"
                    . "We publish $targetTitle at $targetUrl. If you think it would be useful to your readers, you might consider it for the page. Completely your call, of course.\n\n$closing";
                $subjects = ['A resource suggestion for ' . $pageRef, 'Suggestion for your ' . $niche . ' resources page', 'Possible addition to ' . $pageRef];
                break;

            case 'broken_link':
                $broken = self::brokenUrlFromEvidence($evidence);
                if ($broken !== null) {
                    $problem = "I noticed that a link on the page appears to be broken: $broken (our check on " . self::evidenceDate($evidence) . ' returned an error).';
                } else {
                    $problem = 'I was reading the page and wanted to check whether any of its outbound links need updating.';
                    $note = 'No broken link is recorded in the evidence. Run “Scan for broken links” first, or edit the draft — do not claim a broken link you have not verified.';
                }
                $body = "$greeting\n\nI was reading $pageRef on $site. $problem\n\nIf a replacement would help, $targetUrl covers a similar topic" . ($targetTitle !== 'our page' ? " ($targetTitle)" : '') . ". Of course, any source you prefer works just as well — I just wanted to flag it.\n\n$closing";
                $subjects = ['Broken link on ' . $pageRef, 'Quick heads-up about a link on ' . $site, 'Small fix for ' . $pageRef];
                break;

            case 'unlinked_mention':
                $mentionConfirmed = (bool)preg_match('/mentions\s+“?' . preg_quote($brand, '/') . '/iu', $evidence);
                if (!$mentionConfirmed) {
                    $note = 'The brand mention has not been confirmed by the evaluator — verify it before sending.';
                }
                $body = "$greeting\n\nThank you for mentioning $brand in $pageRef" . (!empty($p['normalized_url']) ? ' (' . $p['normalized_url'] . ')' : '') . ".\n\nWould you consider linking the mention to $targetUrl so readers can easily find the page you referenced? Either way, we appreciate the mention.\n\n$closing";
                $subjects = ['Thanks for mentioning ' . $brand, 'A small request about your mention of ' . $brand, 'Re: ' . $brand . ' on ' . $site];
                break;

            case 'partner_page':
                $note = 'Only send this if a genuine supplier/partner/customer relationship exists. Do not claim a relationship that does not exist.';
                $body = "$greeting\n\nI'm $sender from $brand. I noticed $pageRef lists organisations that $site works with.\n\nIf $brand qualifies for the list, we'd be grateful to be included with a link to $targetUrl. Happy to provide any details you need to confirm.\n\n$closing";
                $subjects = ['Listing request for ' . $site, $brand . ' and your partners page', 'Question about ' . $pageRef];
                break;

            default:
                $body = "$greeting\n\nI'm $sender from $brand. I came across $pageRef on $site and thought there might be a way for us to collaborate on content about $niche that would be useful to your readers.\n\nIf that sounds interesting, I'd be happy to share a few ideas.\n\n$closing";
                $subjects = ['Collaboration idea for ' . $site, 'Content idea about ' . $niche, 'Question about ' . $pageRef];
        }
        return self::result($subjects, $body, $seed, $note);
    }

    private static function result(array $subjects, string $body, int $seed, ?string $note): array
    {
        $subjects = array_values(array_unique(array_map(static fn($s) => self::short(trim($s), 150), $subjects)));
        $primary = $subjects[$seed % count($subjects)];
        return [
            'subject' => $primary,
            'alternatives' => array_values(array_filter($subjects, static fn($s) => $s !== $primary)),
            'body' => $body,
            'generator' => 'template',
            'note' => $note,
        ];
    }

    public static function siteName(array $p): string
    {
        $n = trim((string)($p['site_name'] ?? ''));
        if ($n !== '' && mb_strlen($n) <= 60) {
            return $n;
        }
        return (string)($p['domain'] ?? 'your site');
    }

    /** Use the recipient name only when it was publicly provided / entered by the user. */
    public static function greeting(array $p, string $site): string
    {
        $name = trim((string)($p['contact_name'] ?? ''));
        if ($name !== '' && preg_match('/^[\p{L}][\p{L}\'\-. ]{0,60}$/u', $name)) {
            return 'Hi ' . explode(' ', $name)[0] . ',';
        }
        return 'Hello ' . $site . ' team,';
    }

    public static function signature(array $proj, string $sender, string $brand): string
    {
        $sig = trim((string)($proj['outreach_signature'] ?? ''));
        return $sig !== '' ? $sig : $sender . "\n" . $brand . "\n" . ($proj['website_url'] ?? '');
    }

    /** @return string[] three topic ideas */
    public static function topicIdeas(array $topics, string $niche, int $seed): array
    {
        $patterns = [
            'A practical guide to %s for beginners',
            'Common mistakes to avoid with %s',
            'How to choose the right %s: a checklist',
            '%s: what changed this year and what it means for readers',
            'A step-by-step approach to %s on a budget',
            'Questions to ask before investing in %s',
        ];
        $topics = $topics ?: [$niche];
        $ideas = [];
        for ($i = 0; $i < 3; $i++) {
            $topic = $topics[($seed + $i) % count($topics)];
            $ideas[] = sprintf($patterns[($seed + $i * 2) % count($patterns)], $topic);
        }
        return array_values(array_unique($ideas));
    }

    public static function brokenUrlFromEvidence(string $evidence): ?string
    {
        if (preg_match('/\d+ broken: (https?:\/\/\S+)/', $evidence, $m)) {
            return rtrim($m[1], ' ;,');
        }
        return null;
    }

    private static function evidenceDate(string $evidence): string
    {
        return preg_match('/Broken-link scan (\d{4}-\d{2}-\d{2})/', $evidence, $m) ? $m[1] : 'a recent date';
    }

    private static function short(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
