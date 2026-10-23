<?php
declare(strict_types=1);

namespace BharatSeo\Services\Outreach;

use BharatSeo\Core\App;

/**
 * Optional administrator-configured local/open-source model endpoint
 * (OpenAI-compatible chat completions API, e.g. Ollama, llama.cpp server, LM Studio, vLLM).
 * The endpoint is trusted configuration set by a Super Admin only.
 */
class LocalModelClient
{
    public function __construct(protected App $app)
    {
    }

    public function isAvailable(): bool
    {
        $s = $this->app->settings();
        return $s->moduleEnabled('local_ai') && $s->has('ai.endpoint') && $s->has('ai.model');
    }

    /**
     * Ask the model to rewrite a template draft using only supplied facts.
     * @return array{subject:string, body:string}
     * @throws \RuntimeException on timeout, HTTP error or invalid response
     */
    public function rewrite(array $facts, array $templateDraft): array
    {
        $system = 'You write short, polite, professional plain-text outreach emails for an SEO agency. '
            . 'Use ONLY the facts provided. Never invent recipient names, prior conversations, relationships, approvals, statistics, website metrics, prices or guarantees. '
            . 'Do not ask for dofollow links or promise rankings. No HTML. Output exactly: first line "Subject: <subject>", a blank line, then the body.';
        $user = "Facts (JSON):\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            . "\n\nImprove this draft while keeping every factual statement accurate:\nSubject: " . $templateDraft['subject'] . "\n\n" . $templateDraft['body'];
        $payload = [
            'model' => (string)$this->app->settings()->get('ai.model'),
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'temperature' => 0.4,
            'max_tokens' => 700,
            'stream' => false,
        ];
        $content = $this->post($payload);
        return self::parse($content);
    }

    public static function parse(string $content): array
    {
        $content = trim(str_replace("\r\n", "\n", $content));
        if (!preg_match('/^\s*subject:\s*(.+?)\n\s*\n(.+)$/is', $content, $m)) {
            throw new \RuntimeException('Model response did not follow the "Subject:" format.');
        }
        $subject = trim(strip_tags($m[1]), " \t\"'");
        $body = trim(strip_tags($m[2]));
        if ($subject === '' || $body === '') {
            throw new \RuntimeException('Model response was empty.');
        }
        return ['subject' => mb_substr($subject, 0, 200), 'body' => mb_substr($body, 0, 8000)];
    }

    protected function post(array $payload): string
    {
        $endpoint = (string)$this->app->settings()->get('ai.endpoint');
        if (!preg_match('#^https?://#i', $endpoint)) {
            throw new \RuntimeException('Model endpoint must be an http(s) URL.');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $key = (string)$this->app->settings()->get('ai.api_key', '');
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $ch = curl_init($endpoint);
        $timeout = (int)$this->app->settings()->get('ai.timeout', 30);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 1_000_000,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body)) {
            throw new \RuntimeException($errno === CURLE_OPERATION_TIMEDOUT ? 'Model endpoint timed out.' : 'Model endpoint unreachable.');
        }
        if ($status !== 200) {
            throw new \RuntimeException('Model endpoint returned HTTP ' . $status . '.');
        }
        $json = json_decode($body, true);
        $content = $json['choices'][0]['message']['content'] ?? ($json['message']['content'] ?? null);
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('Model endpoint returned an unexpected JSON structure.');
        }
        return $content;
    }
}
