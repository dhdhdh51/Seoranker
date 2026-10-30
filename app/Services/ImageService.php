<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\ValidationException;

/**
 * Safe image uploads: size + MIME (finfo) + getimagesize checks, then re-encoded with GD, which
 * strips EXIF/GPS metadata and any embedded payload. Files get random names in private storage.
 */
final class ImageService
{
    public const MAX_BYTES = 6 * 1048576;
    public const THUMB = 480;
    public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private App $app)
    {
    }

    /** @return string relative path under storage/uploads (e.g. "photos/ab12.jpg") */
    public function store(array $file, string $dir, int $maxSide = 1280): string
    {
        if (!preg_match('/^[a-z]+$/', $dir)) {
            throw new \InvalidArgumentException('Invalid upload directory.');
        }
        $name = $this->encode($this->validated($file), $this->app->config->storagePath('uploads/' . $dir), $maxSide, '', self::THUMB);
        return $dir . '/' . $name;
    }

    /** Re-encode an uploaded image as JPEG into $absDir; returns the random file name. */
    private function encode(string $tmp, string $absDir, int $maxSide, string $prefix = '', int $thumb = 0): string
    {
        $info = @getimagesize($tmp);
        if (!$info || $info[0] < 100 || $info[1] < 100 || $info[0] * $info[1] > 40_000_000) {
            throw ValidationException::single('photo', 'Image must be at least 100×100 pixels and not huge.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw ValidationException::single('photo', 'Image processing (GD) is not available on this server.');
        }
        $src = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => false,
        };
        if (!$src) {
            throw ValidationException::single('photo', 'This image could not be read. Try a JPG or PNG.');
        }
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
            $src = match ($o) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src };
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = (int)max(1, round($w * $scale));
        $nh = (int)max(1, round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!is_dir($absDir) && !@mkdir($absDir, 0750, true)) {
            throw new \RuntimeException('Upload folder is not writable.');
        }
        $name = $prefix . bin2hex(random_bytes(16)) . '.jpg';
        if (!imagejpeg($dst, $absDir . '/' . $name, 82)) {
            throw new \RuntimeException('Could not save the image.');
        }
        if ($thumb > 0 && max($nw, $nh) > $thumb) {
            // Small variant for cards and lists (served as name-sm.jpg)
            $ts = $thumb / max($nw, $nh);
            $tw = (int)max(1, round($nw * $ts));
            $th = (int)max(1, round($nh * $ts));
            $t = imagecreatetruecolor($tw, $th);
            imagecopyresampled($t, $dst, 0, 0, 0, 0, $tw, $th, $nw, $nh);
            imagejpeg($t, $absDir . '/' . substr($name, 0, -4) . '-sm.jpg', 78);
        }
        return $name;
    }

    /** Private documents (verification): kept as uploaded bytes after validation, outside public paths. */
    public function storeDocument(array $file, int $userId): string
    {
        $tmp = $this->validated($file, ['application/pdf' => 'pdf']);
        $dir = $this->app->config->storagePath('private/verifications');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime !== 'application/pdf') {
            $name = $this->encode($tmp, $dir, 2000, $userId . '-');
            @chmod($dir . '/' . $name, 0600);
            return 'private/verifications/' . $name;
        }
        if (!str_starts_with((string)file_get_contents($tmp, false, null, 0, 5), '%PDF-')) {
            throw ValidationException::single('document', 'This PDF could not be read.');
        }
        $name = $userId . '-' . bin2hex(random_bytes(16)) . '.pdf';
        if (!@copy($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('Could not store the document.');
        }
        @chmod($dir . '/' . $name, 0600);
        return 'private/verifications/' . $name;
    }

    private function validated(array $file, array $extra = []): string
    {
        if (!isset($file['error']) || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw ValidationException::single('photo', ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'The file is too large.' : 'Please choose a file to upload.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_BYTES) {
            throw ValidationException::single('photo', 'Files must be smaller than 6 MB.');
        }
        $tmp = (string)$file['tmp_name'];
        if (!is_uploaded_file($tmp) && !(defined('FLATMATES_TESTING') && is_file($tmp))) {
            throw ValidationException::single('photo', 'Invalid upload.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::ALLOWED[$mime]) && !isset($extra[$mime])) {
            throw ValidationException::single('photo', 'Only JPG, PNG or WebP images' . ($extra ? ' or PDF' : '') . ' are allowed.');
        }
        return $tmp;
    }

    public function delete(?string $rel): void
    {
        if ($rel && !str_contains($rel, '..')) {
            $p = str_starts_with($rel, 'private/') ? realpath($this->app->config->storagePath($rel)) : realpath($this->app->config->storagePath('uploads/' . $rel));
            $root = realpath($this->app->config->storagePath());
            if ($p && $root && str_starts_with($p, $root)) {
                @unlink($p);
                @unlink(substr($p, 0, -4) . '-sm.jpg');
            }
        }
    }
}
