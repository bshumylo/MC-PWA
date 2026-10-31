<?php

namespace Espo\Modules\McPwa\Classes\Push;

use Espo\Core\AclManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Builds a push notification payload from a Notification record.
 */
class PayloadBuilder
{
    private const BODY_MAX_LENGTH = 160;

    private ?User $recipient = null;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private Language $language,
        private AclManager $aclManager,
    ) {}

    /**
     * The payload is built with system rights, so every record whose text goes into it
     * is checked against the recipient's read access first. Without access the
     * notification falls back to a generic text.
     *
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public function build(Entity $notification, ?User $recipient = null): array
    {
        $this->recipient = $recipient;

        $type = (string) $notification->get('type');

        $data = $notification->get('data');
        $data = $data ? (object) $data : (object) [];

        $appName = $this->config->get('pwaAppName')
            ?: $this->config->get('applicationName')
            ?: 'CRM';

        [$title, $body, $url] = match ($type) {
            'Assign' => $this->buildAssign($data),
            'EmailReceived' => $this->buildEmailReceived($notification, $data),
            'Message' => [
                $this->translate('newMessage'),
                (string) $notification->get('message'),
                '',
            ],
            'Note' => $this->buildNote($notification),
            'MentionInPost' => $this->buildMention($notification),
            default => [
                $appName,
                (string) ($notification->get('message') ?? $this->translate('newNotification')),
                '',
            ],
        };

        if (trim($title) === '') {
            $title = $appName;
        }

        if (trim($body) === '') {
            $body = $this->translate('newNotification');
        }

        return [
            'title' => $title,
            'body' => mb_substr($body, 0, self::BODY_MAX_LENGTH),
            'url' => $url ?: '#Notification',
            'tag' => 'espo-notification-' . $notification->getId(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildAssign(object $data): array
    {
        $entityType = isset($data->entityType) && is_string($data->entityType)
            ? $data->entityType
            : null;

        $entityName = isset($data->entityName) && is_string($data->entityName)
            ? $data->entityName
            : '';

        $userName = isset($data->userName) && is_string($data->userName)
            ? $data->userName
            : '';

        $scopeLabel = $entityType
            ? $this->language->translate($entityType, 'scopeNames')
            : '';

        $title = $this->translate('assignedToYou');

        $body = trim($scopeLabel . ': ' . $entityName);

        if ($userName !== '') {
            $body .= ' (' . $userName . ')';
        }

        $url = '';

        if ($entityType && isset($data->entityId) && is_string($data->entityId)) {
            if (!$this->loadReadable($entityType, $data->entityId)) {
                return [$title, '', ''];
            }

            $url = '#' . $entityType . '/view/' . $data->entityId;
        }

        return [$title, $body, $url];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildEmailReceived(Entity $notification, object $data): array
    {
        $title = $this->translate('newEmail');

        $body = '';
        $url = '';

        $relatedId = $notification->get('relatedId');
        $relatedType = $notification->get('relatedType');

        if ($relatedType === 'Email' && $relatedId) {
            $email = $this->loadReadable('Email', $relatedId);

            if ($email) {
                $body = (string) ($email->get('name') ?? '');
            }

            if ($email) {
                $url = '#Email/view/' . $relatedId;
            }
        }

        if (isset($data->personEntityName) && is_string($data->personEntityName)) {
            $body = trim($data->personEntityName . ': ' . $body, ': ');
        }

        return [$title, $body, $url];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildNote(Entity $notification): array
    {
        $title = $this->translate('newPost');

        $body = '';
        $url = '';

        $relatedId = $notification->get('relatedId');
        $relatedType = $notification->get('relatedType');

        if ($relatedType === 'Note' && $relatedId) {
            $note = $this->loadReadable('Note', $relatedId);

            if ($note) {
                $body = (string) ($note->get('post') ?? '');

                $parentType = $note->get('parentType');
                $parentId = $note->get('parentId');

                if ($parentType && $parentId) {
                    $parent = $this->loadReadable($parentType, $parentId);

                    if ($parent) {
                        $url = '#' . $parentType . '/view/' . $parentId;
                    }

                    if ($parent && $parent->hasAttribute('name')) {
                        $title .= ': ' . $parent->get('name');
                    }
                }
            }
        }

        return [$title, strip_tags($body), $url];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildMention(Entity $notification): array
    {
        $title = $this->translate('youWereMentioned');

        $body = '';
        $url = '';

        $relatedId = $notification->get('relatedId');
        $relatedType = $notification->get('relatedType');

        if ($relatedType === 'Note' && $relatedId) {
            $note = $this->loadReadable('Note', $relatedId);

            if ($note) {
                $body = (string) ($note->get('post') ?? '');

                $parentType = $note->get('parentType');
                $parentId = $note->get('parentId');

                if ($parentType && $parentId && $this->loadReadable($parentType, $parentId)) {
                    $url = '#' . $parentType . '/view/' . $parentId;
                }
            }
        }

        return [$title, strip_tags($body), $url];
    }

    /**
     * Loads a record only when the recipient is allowed to read it.
     */
    private function loadReadable(string $entityType, string $id): ?Entity
    {
        if (!$this->recipient || !$this->recipient->isActive()) {
            return null;
        }

        try {
            $entity = $this->entityManager->getEntityById($entityType, $id);
        } catch (\Throwable) {
            return null;
        }

        if (!$entity || !$this->aclManager->checkEntityRead($this->recipient, $entity)) {
            return null;
        }

        return $entity;
    }

    private function translate(string $key): string
    {
        return $this->language->translate($key, 'mcPwaPush');
    }
}
