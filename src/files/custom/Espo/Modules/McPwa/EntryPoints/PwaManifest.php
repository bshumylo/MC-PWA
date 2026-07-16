<?php

namespace Espo\Modules\McPwa\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;

/**
 * Serves the Web App Manifest built from admin settings.
 * URL: ?entryPoint=pwaManifest
 */
class PwaManifest implements EntryPoint
{
    use NoAuth;

    private const MAX_SHORTCUTS = 6;

    public function __construct(
        private Config $config,
        private Language $language,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $base = $this->getBasePath();

        $appName = $this->config->get('pwaAppName')
            ?: $this->config->get('applicationName')
            ?: 'CRM';

        $shortName = $this->config->get('pwaShortName') ?: mb_substr($appName, 0, 12);

        $manifest = [
            'id' => $base,
            'name' => $appName,
            'short_name' => $shortName,
            'description' => $appName,
            'start_url' => $base,
            'scope' => $base,
            'display' => 'standalone',
            'theme_color' => $this->config->get('pwaThemeColor') ?: '#337ab7',
            'background_color' => $this->config->get('pwaBackgroundColor') ?: '#ffffff',
            'lang' => $this->config->get('language') ?: 'en_US',
            'prefer_related_applications' => false,
            'icons' => [
                [
                    'src' => $base . '?entryPoint=pwaIcon&size=192',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => $base . '?entryPoint=pwaIcon&size=512',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => $base . '?entryPoint=pwaIcon&size=192&maskable=1',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => $base . '?entryPoint=pwaIcon&size=512&maskable=1',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
            'shortcuts' => $this->buildShortcuts($base),
        ];

        $response
            ->setHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-cache');

        $response->writeBody(
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildShortcuts(string $base): array
    {
        $shortcuts = [];

        $quickCreateList = $this->config->get('quickCreateList') ?? [];

        if (!is_array($quickCreateList)) {
            return [];
        }

        foreach (array_slice($quickCreateList, 0, self::MAX_SHORTCUTS) as $scope) {
            if (!is_string($scope) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $scope)) {
                continue;
            }

            $label = $this->language->translate($scope, 'scopeNames');
            $createLabel = $this->language->translate('Create', 'labels');

            $shortcuts[] = [
                'name' => $label,
                'description' => $createLabel . ': ' . $label,
                'url' => $base . '#' . $scope . '/create',
                'icons' => [
                    [
                        'src' => $base . '?entryPoint=pwaIcon&size=192',
                        'sizes' => '192x192',
                        'type' => 'image/png',
                    ],
                ],
            ];
        }

        return $shortcuts;
    }

    private function getBasePath(): string
    {
        $siteUrl = rtrim((string) $this->config->get('siteUrl'), '/');

        $path = parse_url($siteUrl, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '/';
        }

        return rtrim($path, '/') . '/';
    }
}
