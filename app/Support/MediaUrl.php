<?php

declare(strict_types=1);

namespace App\Support;

use Aws\CloudFront\UrlSigner;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Signed URLs for user media. The bucket is private; the app's API (kive-backend,
 * MediaStorageService) signs the same way — a CloudFront signed URL for keys under
 * the distribution's origin path, an S3 presigned URL otherwise.
 */
final class MediaUrl
{
    private static ?UrlSigner $signer = null;

    /** A loadable URL for a stored key or one of our own stored URLs; anyone else's URL unchanged. */
    public static function sign(?string $keyOrUrl): ?string
    {
        if ($keyOrUrl === null || trim($keyOrUrl) === '') {
            return null;
        }

        $key = self::keyFrom($keyOrUrl);
        if ($key === null) {
            return $keyOrUrl;
        }

        $expires = (int) (ceil((time() + self::cfg('ttl', 86400)) / 3600) * 3600);
        $path = self::cdnPath($key);

        if ($path !== null && ($signer = self::signer()) !== null) {
            try {
                return $signer->getSignedUrl(self::cdnBase().'/'.$path, $expires);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            return Storage::disk((string) config('filesystems.media.disk', 's3'))->temporaryUrl($key, now()->setTimestamp($expires));
        } catch (Throwable) {
            return $keyOrUrl;
        }
    }

    private static function keyFrom(string $value): ?string
    {
        if (! preg_match('#^https?://#i', $value)) {
            return ltrim($value, '/');
        }

        $path = ltrim(rawurldecode((string) parse_url($value, PHP_URL_PATH)), '/');
        $origin = strtolower(parse_url($value, PHP_URL_SCHEME).'://'.parse_url($value, PHP_URL_HOST));

        if (self::cdnBase() !== '' && strtolower(self::cdnBase()) === $origin) {
            return self::originPath() === '' ? $path : self::originPath().'/'.$path;
        }

        $bucket = rtrim((string) config('filesystems.media.url', ''), '/');
        if ($bucket !== '' && str_starts_with(strtolower($value), strtolower($bucket).'/')) {
            return $path;
        }
        // The bucket's default virtual-hosted address.
        $name = (string) config('filesystems.disks.s3.bucket', '');
        if ($name !== '' && str_starts_with((string) parse_url($value, PHP_URL_HOST), $name.'.s3.')) {
            return $path;
        }

        return null;
    }

    private static function cdnPath(string $key): ?string
    {
        if (self::cdnBase() === '') {
            return null;
        }
        $origin = self::originPath();

        return $origin === '' ? $key : (str_starts_with($key, $origin.'/') ? substr($key, strlen($origin) + 1) : null);
    }

    private static function signer(): ?UrlSigner
    {
        if (self::$signer !== null) {
            return self::$signer;
        }

        $id = (string) self::cfg('key_pair_id', '');
        $pem = str_replace('\n', "\n", (string) self::cfg('private_key', ''));
        $path = (string) self::cfg('private_key_path', '');
        if ($pem === '' && $path !== '') {
            $file = str_starts_with($path, '/') ? $path : base_path($path);
            $pem = is_readable($file) ? (string) file_get_contents($file) : '';
        }

        return $id !== '' && $pem !== '' ? self::$signer = new UrlSigner($id, $pem) : null;
    }

    private static function cdnBase(): string
    {
        return rtrim((string) self::cfg('url', ''), '/');
    }

    private static function originPath(): string
    {
        return trim((string) self::cfg('origin_path', ''), '/');
    }

    private static function cfg(string $key, mixed $default = null): mixed
    {
        return config("filesystems.media.cloudfront.$key", $default);
    }
}
