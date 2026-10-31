<?php

namespace Espo\Modules\McPwa\Classes;

use Espo\Core\Utils\Language;

/**
 * Translation helper: the core Language::translate() may return a list for option-like categories,
 * the module needs plain strings only.
 */
final class Text
{
    public static function translate(Language $language, string $name, string $category, string $scope = 'Global'): string
    {
        $value = $language->translate($name, $category, $scope);

        return is_string($value) ? $value : $name;
    }
}
