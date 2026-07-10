<?php

namespace Espo\Modules\McPwa\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\Config;

/**
 * Public (non-sensitive) PWA configuration for the client script.
 * URL: ?entryPoint=pwaConfig
 */
class PwaConfig implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private Config $config,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $data = [
            'enabled' => (bool) $this->config->get('pwaEnabled'),
            'pushEnabled' => (bool) $this->config->get('pwaPushEnabled'),
            'statsEnabled' => (bool) $this->config->get('pwaStatsEnabled'),
            'themeColor' => $this->config->get('pwaThemeColor') ?: '#337ab7',
            'vapidPublicKey' => (string) ($this->config->get('pwaVapidPublicKey') ?? ''),
        ];

        $response
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-cache');

        $response->writeBody(json_encode($data));
    }
}
