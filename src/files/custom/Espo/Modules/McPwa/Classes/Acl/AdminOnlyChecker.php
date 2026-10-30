<?php

namespace Espo\Modules\McPwa\Classes\Acl;

use Espo\Core\Acl\AccessChecker;
use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Access checker for module-managed entities (PwaSubscription, PwaInstallation).
 *
 * Rows are written only by the module's own API actions and jobs, which use
 * the entity manager directly and bypass ACL. Through the generic REST API
 * only administrators may read or delete rows; nobody may create or edit them.
 *
 * @implements AccessEntityCREDChecker<Entity>
 */
class AdminOnlyChecker implements AccessChecker, AccessEntityCREDChecker
{
    public function check(User $user, ScopeData $data): bool
    {
        return $user->isAdmin();
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return false;
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return $user->isAdmin();
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return false;
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return $user->isAdmin();
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $user->isAdmin();
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $user->isAdmin();
    }
}
