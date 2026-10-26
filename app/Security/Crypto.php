<?php
declare(strict_types=1);

namespace Flatmates\Security;

use Flatmates\Core\App;

/**
 * Authenticated symmetric encryption (AES-256-GCM via OpenSSL) for sensitive
 * settings such as SMTP passwords and API keys. The key lives in the config file.
 */
final class Crypto
{
    private string $key;

    public function __construct(App|string $appOrKey)
    {
        $raw = $appOrKey instanceof App ? (string)$appOrKey->config->get('app.key') : $appOrKey;
        $decoded = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : $raw;
        if (!is_string($decoded) || strlen($decoded) < 32) {
            throw new \RuntimeException('Application encryption key is missing or too short.');
        }
        $this->key = hash('sha256', $decoded, true);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'bharatseo', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new \RuntimeException('Unsupported ciphertext.');
        }
        $bin = base64_decode(substr($payload, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            throw new \RuntimeException('Malformed ciphertext.');
        }
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), 'bharatseo');
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }

    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
