<?php

namespace Espo\Modules\McPwa\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Throwable;

/**
 * Returns the bottom navigation bar configuration for the mobile app.
 * GET /api/v1/McPwa/bottomBar (authenticated).
 *
 * Items are stored in the same format as the core tabList: scope name
 * strings and {type: 'url'} objects. Scope items are resolved to a
 * url/label/icon here, with an ACL check for the current user.
 */
class GetBottomBar implements Action
{
    private const MAX_ITEMS = 8;
    private const DEFAULT_ICON = 'fas fa-cube';

    public function __construct(
        private Config $config,
        private User $user,
        private Acl $acl,
        private Metadata $metadata,
        private Language $language,
    ) {}

    public function process(Request $request): Response
    {
        if ($this->user->isPortal()) {
            throw new Forbidden('Not allowed for portal users.');
        }

        if (
            !$this->config->get('pwaEnabled') ||
            !$this->config->get('pwaBottomBarEnabled')
        ) {
            return ResponseComposer::json(['enabled' => false]);
        }

        $rawList = $this->config->get('pwaBottomBarItems') ?? [];

        if (!is_array($rawList)) {
            $rawList = [];
        }

        $itemList = [];

        foreach ($rawList as $raw) {
            if (count($itemList) >= self::MAX_ITEMS) {
                break;
            }

            $item = $this->resolveItem($raw);

            if ($item !== null) {
                $itemList[] = $item;
            }
        }

        $themeColor = $this->config->get('pwaThemeColor');

        if (!is_string($themeColor) || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeColor)) {
            $themeColor = '';
        }

        return ResponseComposer::json([
            'enabled' => true,
            'showLabels' => (bool) $this->config->get('pwaBottomBarShowLabels'),
            'themeColor' => $themeColor,
            'items' => $itemList,
        ]);
    }

    /**
     * @param mixed $raw
     * @return ?array<string, mixed>
     */
    private function resolveItem(mixed $raw): ?array
    {
        if (is_string($raw)) {
            return $this->resolveScopeItem($raw);
        }

        if (is_object($raw)) {
            $raw = get_object_vars($raw);
        }

        if (!is_array($raw)) {
            return null;
        }

        $type = $raw['type'] ?? null;

        if ($type === 'url') {
            return $this->resolveUrlItem($raw);
        }

        if ($type === null && isset($raw['url'])) {
            // Legacy v1.0.x format: {url, label, iconClass, iconColor}.
            return $this->composeItem(
                $raw['url'] ?? null,
                $raw['label'] ?? null,
                $raw['iconClass'] ?? null,
                $raw['iconColor'] ?? null,
                false
            );
        }

        return null;
    }

    /** @return ?array<string, mixed> */
    private function resolveScopeItem(string $scope): ?array
    {
        if (
            $scope === '' ||
            !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $scope) ||
            !$this->metadata->get(['scopes', $scope])
        ) {
            return null;
        }

        if ($this->metadata->get(['scopes', $scope, 'disabled'])) {
            return null;
        }

        if (!$this->checkAclScope($scope)) {
            return null;
        }

        return $this->composeItem(
            '#' . $scope,
            $this->language->translate($scope, 'scopeNamesPlural'),
            $this->metadata->get(['clientDefs', $scope, 'iconClass']) ?? self::DEFAULT_ICON,
            $this->metadata->get(['clientDefs', $scope, 'color']),
            false
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return ?array<string, mixed>
     */
    private function resolveUrlItem(array $raw): ?array
    {
        if (!empty($raw['onlyAdmin']) && !$this->user->isAdmin()) {
            return null;
        }

        $aclScope = $raw['aclScope'] ?? null;

        if (
            is_string($aclScope) &&
            $aclScope !== '' &&
            !$this->checkAclScope($aclScope)
        ) {
            return null;
        }

        return $this->composeItem(
            $raw['url'] ?? null,
            $raw['text'] ?? null,
            $raw['iconClass'] ?? null,
            $raw['color'] ?? null,
            !empty($raw['openInNewTab'])
        );
    }

    private function checkAclScope(string $scope): bool
    {
        if (!$this->metadata->get(['scopes', $scope, 'acl'])) {
            // No ACL defined for the scope – accessible.
            return true;
        }

        try {
            return $this->acl->checkScope($scope);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return ?array<string, mixed> */
    private function composeItem(
        mixed $url,
        mixed $label,
        mixed $iconClass,
        mixed $iconColor,
        bool $newTab,
    ): ?array {

        $url = $this->str($url, 500);

        if ($url === '' || !$this->isUrlAllowed($url)) {
            return null;
        }

        $iconColor = $this->str($iconColor, 30);

        if ($iconColor !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $iconColor)) {
            $iconColor = '';
        }

        $iconClass = $this->str($iconClass, 100);

        if ($iconClass !== '' && !preg_match('/^[a-zA-Z0-9 _\-]+$/', $iconClass)) {
            $iconClass = '';
        }

        return [
            'url' => $url,
            'label' => $this->str($label, 60),
            'iconClass' => $iconClass,
            'iconColor' => $iconColor,
            'newTab' => $newTab,
        ];
    }

    private function isUrlAllowed(string $url): bool
    {
        if (str_starts_with($url, '#')) {
            return true;
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return true;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        return false;
    }

    private function str(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(mb_substr(strip_tags($value), 0, $maxLength));
    }
}
