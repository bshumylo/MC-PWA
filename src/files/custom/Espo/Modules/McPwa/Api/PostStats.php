<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Collects anonymous installation statistics (no link to the user is stored).
 * POST /api/v1/McPwa/stats
 */
class PostStats implements Action
{
    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private User $user,
    ) {}

    public function process(Request $request): Response
    {
        if ($request->getHeader('X-Requested-With') !== 'XMLHttpRequest') {
            throw new Forbidden('Missing required header.');
        }

        if (
            !$this->config->get('pwaEnabled') ||
            !$this->config->get('pwaStatsEnabled')
        ) {
            return ResponseComposer::json(['disabled' => true]);
        }

        if ($this->user->isPortal()) {
            throw new Forbidden('Not allowed for portal users.');
        }

        $body = $request->getParsedBody();

        $anonymousId = $body->anonymousId ?? null;

        if (
            !is_string($anonymousId) ||
            !preg_match('/^[a-f0-9\-]{8,64}$/', $anonymousId)
        ) {
            throw new BadRequest('Invalid anonymousId.');
        }

        $platform = $this->sanitize($body->platform ?? null, 60) ?? 'unknown';
        $osVersion = $this->sanitize($body->osVersion ?? null, 60);
        $language = $this->sanitize($body->language ?? null, 10);
        $userAgent = $this->sanitize($body->userAgent ?? null, 255);

        $deviceType = $body->deviceType ?? 'other';

        if (!in_array($deviceType, ['phone', 'tablet', 'desktop', 'other'], true)) {
            $deviceType = 'other';
        }

        $now = date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $installation = $this->entityManager
            ->getRDBRepository('PwaInstallation')
            ->where(['anonymousId' => $anonymousId])
            ->findOne();

        if (!$installation) {
            $installation = $this->entityManager->getNewEntity('PwaInstallation');

            $installation->set([
                'anonymousId' => $anonymousId,
                'installedAt' => $now,
            ]);
        }

        $installation->set([
            'name' => $platform . ' / ' . $deviceType,
            'platform' => $platform,
            'osVersion' => $osVersion,
            'deviceType' => $deviceType,
            'language' => $language,
            'userAgent' => $userAgent,
            'lastSeenAt' => $now,
        ]);

        $this->entityManager->saveEntity($installation);

        return ResponseComposer::json(['success' => true]);
    }

    private function sanitize(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return mb_substr(strip_tags($value), 0, $maxLength);
    }
}
