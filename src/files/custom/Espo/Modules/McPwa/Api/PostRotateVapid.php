<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\McPwa\Classes\Push\VapidKeyGenerator;
use Espo\ORM\EntityManager;

/**
 * Replaces the VAPID key pair. Existing subscriptions are bound to the old public key and stop working,
 * so they are removed; devices subscribe again the next time the app is opened.
 *
 * POST /api/v1/McPwa/rotateVapid {confirm: true}
 */
class PostRotateVapid implements Action
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private VapidKeyGenerator $generator,
        private Log $log,
    ) {}

    public function process(Request $request): Response
    {
        if ($request->getHeader('X-Requested-With') !== 'XMLHttpRequest') {
            throw new Forbidden('Missing required header.');
        }

        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only administrators can rotate the VAPID keys.');
        }

        if (($request->getParsedBody()->confirm ?? null) !== true) {
            throw new BadRequest('Confirmation is required.');
        }

        $keys = $this->generator->generate();

        $configWriter = $this->injectableFactory->create(ConfigWriter::class);

        $configWriter->set('pwaVapidPublicKey', $keys['publicKey']);
        $configWriter->set('pwaVapidPrivateKey', $keys['privateKey']);
        $configWriter->save();

        $removed = $this->entityManager->getRDBRepository('PwaSubscription')->count();

        $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager->getQueryBuilder()->delete()->from('PwaSubscription')->build()
        );

        $this->log->notice('McPwa: VAPID keys rotated.', [
            'userId' => $this->user->getId(),
            'removedSubscriptions' => $removed,
        ]);

        return ResponseComposer::json([
            'success' => true,
            'vapidPublicKey' => $keys['publicKey'],
            'removedSubscriptions' => $removed,
        ]);
    }
}
