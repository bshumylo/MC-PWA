<?php

namespace Espo\Modules\McPwa\Classes\Push;

use RuntimeException;

/**
 * Thrown when a push service reports that a subscription no longer exists (404/410).
 */
class ExpiredSubscriptionException extends RuntimeException
{
}
