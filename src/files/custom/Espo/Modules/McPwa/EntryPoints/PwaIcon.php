<?php

namespace Espo\Modules\McPwa\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Utils\Config;
use Espo\Entities\Attachment;
use Espo\ORM\EntityManager;

/**
 * Serves the app icon, resized to a square PNG.
 *
 * Source priority:
 *  1. The icon uploaded by the admin (PWA Settings).
 *  2. The company logo (if set).
 *  3. A generated placeholder (theme-color square with the app initial).
 *
 * URL: ?entryPoint=pwaIcon&size=192|512
 */
class PwaIcon implements EntryPoint
{
    use NoAuth;

    private const SIZES = [192, 512];

    /** Source images above these limits are refused before decoding. */
    private const MAX_SIDE = 4096;
    private const MAX_PIXELS = 16000000;

    private const CACHE_DIR = 'data/cache/mc-pwa';

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $size = (int) ($request->getQueryParam('size') ?? 192);

        if (!in_array($size, self::SIZES, true)) {
            $size = 192;
        }

        $maskable = (bool) $request->getQueryParam('maskable');

        $content = $this->getAttachmentIcon('pwaIconId', $size, $maskable)
            ?? $this->getAttachmentIcon('companyLogoId', $size, $maskable)
            ?? $this->generatePlaceholder($size);

        $response->setHeader('X-Content-Type-Options', 'nosniff');

        if ($content === null || $content === '') {
            $response
                ->setStatus(404)
                ->setHeader('Cache-Control', 'no-store');

            return;
        }

        $etag = '"' . md5($content) . '"';

        $response
            ->setHeader('ETag', $etag)
            ->setHeader('Cache-Control', 'public, max-age=600');

        if ($request->getHeader('If-None-Match') === $etag) {
            $response->setStatus(304);

            return;
        }

        $response
            ->setHeader('Content-Type', 'image/png')
            ->writeBody($content);
    }

    private function getAttachmentIcon(string $configParam, int $size, bool $maskable = false): ?string
    {
        $attachmentId = $this->config->get($configParam);

        if (!$attachmentId || !is_string($attachmentId)) {
            return null;
        }

        $attachment = $this->entityManager
            ->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

        if (!$attachment instanceof Attachment) {
            return null;
        }

        if (!in_array($attachment->getType(), ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        $cacheFile = self::CACHE_DIR . '/icon-' . sha1(implode('|', [
            $attachmentId,
            (string) $attachment->get('modifiedAt'),
            $size,
            (int) $maskable,
            (string) $this->config->get('pwaBackgroundColor'),
            (string) $this->config->get('pwaThemeColor'),
        ])) . '.png';

        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);

            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }

        try {
            $source = $this->fileStorageManager->getContents($attachment);
        } catch (\Throwable) {
            return null;
        }

        $info = @getimagesizefromstring($source);

        if (
            !$info ||
            $info[0] < 1 || $info[1] < 1 ||
            $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE ||
            $info[0] * $info[1] > self::MAX_PIXELS
        ) {
            return null;
        }

        $png = $this->resizeToSquarePng($source, $size, $maskable);

        if ($png !== null) {
            if (!is_dir(self::CACHE_DIR)) {
                @mkdir(self::CACHE_DIR, 0775, true);
            }

            @file_put_contents($cacheFile, $png, LOCK_EX);
        }

        return $png;
    }

    private function resizeToSquarePng(string $source, int $size, bool $maskable = false): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($source);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $canvas = imagecreatetruecolor($size, $size);

        if ($maskable) {
            // Maskable icons must fill the whole canvas: solid background,
            // content within the ~80% safe zone (Android crops to a circle).
            [$r, $g, $b] = $this->parseHexColor(
                (string) ($this->config->get('pwaBackgroundColor')
                    ?: $this->config->get('pwaThemeColor')
                    ?: '#337ab7')
            );

            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
            imagealphablending($canvas, true);
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);

            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagealphablending($canvas, true);
        }

        $safeZone = $maskable ? 0.8 : 1.0;

        $scale = min($size / $width, $size / $height) * $safeZone;

        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);

        imagecopyresampled(
            $canvas,
            $image,
            (int) (($size - $newWidth) / 2),
            (int) (($size - $newHeight) / 2),
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        if (!$maskable) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }

        ob_start();
        imagepng($canvas);
        $result = ob_get_clean();

        imagedestroy($image);
        imagedestroy($canvas);

        return $result !== false ? $result : null;
    }

    /**
     * A solid theme-color square with the first letter of the app name.
     * Used only until the admin uploads a real icon.
     */
    private function generatePlaceholder(int $size): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $canvas = imagecreatetruecolor($size, $size);

        [$r, $g, $b] = $this->parseHexColor(
            (string) ($this->config->get('pwaThemeColor') ?: '#337ab7')
        );

        $background = imagecolorallocate($canvas, $r, $g, $b);
        imagefill($canvas, 0, 0, $background);

        $appName = $this->config->get('pwaAppName')
            ?: $this->config->get('applicationName')
            ?: 'E';

        $letter = strtoupper(mb_substr(trim((string) $appName), 0, 1));

        if (!preg_match('/^[A-Z0-9]$/', $letter)) {
            $letter = 'E';
        }

        // Scale the built-in font up to ~50% of the icon height.
        $font = 5;
        $charWidth = imagefontwidth($font);
        $charHeight = imagefontheight($font);

        $letterImage = imagecreatetruecolor($charWidth, $charHeight);
        imagefill($letterImage, 0, 0, imagecolorallocate($letterImage, $r, $g, $b));
        imagestring($letterImage, $font, 0, 0, $letter, imagecolorallocate($letterImage, 255, 255, 255));

        $targetHeight = (int) ($size * 0.5);
        $targetWidth = (int) ($charWidth * $targetHeight / $charHeight);

        imagecopyresized(
            $canvas,
            $letterImage,
            (int) (($size - $targetWidth) / 2),
            (int) (($size - $targetHeight) / 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $charWidth,
            $charHeight
        );

        imagedestroy($letterImage);

        ob_start();
        imagepng($canvas);
        $result = ob_get_clean();

        imagedestroy($canvas);

        return $result !== false ? $result : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function parseHexColor(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [51, 122, 183];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
