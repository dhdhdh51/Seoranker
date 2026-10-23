<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Services\Outreach\ContentGuard;
use BharatSeo\Services\Outreach\LocalModelClient;
use BharatSeo\Services\Outreach\OutreachWriter;
use BharatSeo\Services\ProspectService;
use BharatSeo\Tests\TestCase;

final class OutreachWriterTest extends TestCase
{
    private function ctx(array $prospect = []): array
    {
        return [
            'prospect' => $prospect + ['id' => 7, 'domain' => 'krishihub.example', 'site_name' => 'Krishi Hub', 'title' => 'Organic farming resources', 'normalized_url' => 'https://krishihub.example/resources/', 'evidence' => '', 'contact_name' => null],
            'project' => ['domain' => 'greenfarm.in', 'website_url' => 'https://greenfarm.in/', 'brand_name' => 'Green Farm', 'niche' => 'organic farming', 'outreach_signature' => ''],
            'target' => ['url' => 'https://greenfarm.in/guides/composting/', 'title' => 'Home composting guide'],
            'keywords' => ['compost', 'soil health'],
            'topics' => [],
            'sender' => 'Priya',
        ];
    }

    public function testEveryTemplateIsCleanAndNeverInventsNames(): void
    {
        $w = new OutreachWriter();
        foreach (['guest_post', 'expert_contribution', 'resource_page', 'broken_link', 'unlinked_mention', 'partner_page', 'general', 'follow_up'] as $type) {
            $d = $w->draft($type, $this->ctx() + ['original_subject' => 'Idea', 'original_sent_at' => '2026-10-01 10:00:00']);
            $this->assertSame([], ContentGuard::problems($d['subject'], $d['body'], null), "Template $type must pass the content guard");
            $this->assertContains('Hello Krishi Hub team,', $d['body'], "$type must not invent a recipient name");
            $this->assertTrue(count($d['alternatives']) >= 1, "$type must offer subject alternatives");
            $this->assertSame('template', $d['generator']);
        }
    }

    public function testUsesPubliclyProvidedNameOnly(): void
    {
        $d = (new OutreachWriter())->draft('resource_page', $this->ctx(['contact_name' => 'Meera Iyer']));
        $this->assertContains('Hi Meera,', $d['body']);
    }

    public function testBrokenLinkTemplateRequiresEvidence(): void
    {
        $w = new OutreachWriter();
        $none = $w->draft('broken_link', $this->ctx());
        $this->assertTrue($none['note'] !== null && str_contains($none['note'], 'No broken link'), 'Missing evidence must produce a warning note');
        $this->assertNotContains('appears to be broken', $none['body']);
        $ev = 'Broken-link scan 2026-10-20 UTC: checked 9 outbound link(s), 1 broken: https://old.example/guide (HTTP 404)';
        $with = $w->draft('broken_link', $this->ctx(['evidence' => $ev]));
        $this->assertContains('https://old.example/guide', $with['body']);
        $this->assertContains('2026-10-20', $with['body']);
        $this->assertSame(null, $with['note']);
    }

    public function testContentGuardBlocksFabricationAndSpamClaims(): void
    {
        $cases = [
            'Hi John,\n\nAs we discussed last week, here is the link.' => 'name',
            "Hello team,\n\nOur site has Domain Authority 55 and we guarantee rankings." => 'metrics',
            "Hello team,\n\nPlease add a dofollow link for us, thanks a lot for your help today." => 'dofollow',
            "Hello [First Name],\n\nThis is a long enough message body for the guard to inspect." => 'placeholder',
            "Hello team,\n\nYou approved our article last month, so here is the next one for you." => 'approval',
        ];
        foreach ($cases as $body => $label) {
            $this->assertTrue(ContentGuard::problems('Subject ok', str_replace('\n', "\n", $body), null) !== [], "Guard must block: $label");
        }
        $this->assertSame([], ContentGuard::problems('Subject ok', "Hi John,\n\nThanks for your article on compost, I enjoyed it a lot.", 'John Mathew'));
    }

    public function testLocalModelResponseParsing(): void
    {
        $p = LocalModelClient::parse("Subject: A resource for your readers\n\nHello Krishi Hub team,\n\nBody text.");
        $this->assertSame('A resource for your readers', $p['subject']);
        $this->assertContains('Body text.', $p['body']);
        $this->assertThrows(\RuntimeException::class, fn() => LocalModelClient::parse('no subject line here'));
    }

    public function testProspectWorkflowTransitions(): void
    {
        $this->assertTrue(ProspectService::canTransition('discovered', 'evaluated'));
        $this->assertTrue(ProspectService::canTransition('evaluated', 'needs_review'));
        $this->assertTrue(ProspectService::canTransition('needs_review', 'approved'));
        $this->assertTrue(ProspectService::canTransition('approved', 'outreach_sent'));
        $this->assertTrue(ProspectService::canTransition('approved', 'submitted'));
        $this->assertTrue(ProspectService::canTransition('outreach_sent', 'verified'));
        $this->assertFalse(ProspectService::canTransition('discovered', 'approved'), 'Cannot skip review');
        $this->assertFalse(ProspectService::canTransition('evaluated', 'outreach_sent'));
        $this->assertFalse(ProspectService::canTransition('rejected', 'approved'));
        $this->assertFalse(ProspectService::canTransition('needs_review', 'verified'));
    }
}
