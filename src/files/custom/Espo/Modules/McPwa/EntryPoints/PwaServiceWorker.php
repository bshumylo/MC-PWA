<?php

namespace Espo\Modules\McPwa\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\File\Manager as FileManager;

/**
 * Serves the service worker script from the root scope.
 * URL: ?entryPoint=pwaServiceWorker
 */
class PwaServiceWorker implements EntryPoint
{
    use NoAuth;

    private const FILE_PATH = 'client/custom/modules/mc-pwa/pwa-sw.js';

    public function __construct(
        private FileManager $fileManager,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $content = $this->fileManager->getContents(self::FILE_PATH);

        $response
            ->setHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->setHeader('Cache-Control', 'no-cache')
            ->setHeader('X-Content-Type-Options', 'nosniff');

        $response->writeBody($content !== false ? $content : '');
    }
}
