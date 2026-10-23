<?php
declare(strict_types=1);

namespace BharatSeo\Services\Outreach;

/**
 * Validates outreach text (especially model output) against fabrication and spam rules.
 */
final class ContentGuard
{
    private const FORBIDDEN = [
        '/\bas (we|i) (discussed|agreed|talked)\b/i' => 'claims a previous conversation',
        '/\b(following|per) our (call|conversation|meeting|chat)\b/i' => 'claims a previous conversation',
        '/\byou (approved|agreed|accepted|confirmed)\b/i' => 'claims an approval that did not happen',
        '/\b(our|the) (long-?standing|previous|existing) (relationship|partnership|collaboration)\b/i' => 'claims a prior relationship',
        '/\b(domain authority|domain rating|page ?rank|trust ?flow|citation flow)\b/i' => 'cites website metrics',
        '/\b(DA|DR|PA)\s*[:=]?\s*\d{1,3}\b/' => 'cites website metrics',
        '/\bguarantee[ds]?\b/i' => 'makes a guarantee',
        '/\b(do-?follow)\b/i' => 'requests or promises a dofollow link',
        '/\b(rank(ing)? (#|number )?1|first page of google|boost your rankings?)\b/i' => 'makes a ranking claim',
        '/\[(first ?name|name|your name|company|website|insert[^\]]*)\]/i' => 'contains an unfilled placeholder',
        '/\{\{[^}]+\}\}|<insert/i' => 'contains an unfilled placeholder',
        '/\b(we will pay|paid link|buy a link|link exchange)\b/i' => 'proposes a link scheme',
    ];

    /** @return string[] problems (empty when clean) */
    public static function problems(string $subject, string $body, ?string $knownName): array
    {
        $problems = [];
        $text = $subject . "\n" . $body;
        foreach (self::FORBIDDEN as $re => $why) {
            if (preg_match($re, $text)) {
                $problems[$why] = 'Text ' . $why . '.';
            }
        }
        // Greeting with a name we do not have = fabricated recipient name.
        if (preg_match('/^\s*(hi|hello|dear|hey)\s+([\p{Lu}][\p{L}\'\-]+)\s*[,!]/mu', $body, $m)) {
            $name = $m[2];
            $allowed = ['team', 'there', 'editor', 'editors', 'admin', 'webmaster', 'sir', 'madam'];
            $known = $knownName !== null && $knownName !== '' && stripos($knownName, $name) !== false;
            if (!$known && !in_array(strtolower($name), $allowed, true) && !preg_match('/\b' . preg_quote($name, '/') . '\s+team\b/i', $body)) {
                $problems['name'] = 'Greeting uses a recipient name (“' . $name . '”) that is not in the prospect record.';
            }
        }
        if (mb_strlen(trim($subject)) < 3 || mb_strlen($subject) > 200) {
            $problems['subject'] = 'Subject must be 3–200 characters.';
        }
        if (mb_strlen(trim($body)) < 40) {
            $problems['body_short'] = 'Message body is too short.';
        }
        if (mb_strlen($body) > 8000) {
            $problems['body_long'] = 'Message body is too long (max 8000 characters).';
        }
        if (preg_match('/<\s*(script|iframe|a\s|img)\b/i', $body)) {
            $problems['html'] = 'Plain-text emails only; remove HTML markup.';
        }
        return array_values($problems);
    }
}
