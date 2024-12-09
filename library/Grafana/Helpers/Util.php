<?php

namespace Icinga\Module\Grafana\Helpers;

use Icinga\User;

class Util
{
    public static function graphiteReplace(string $string = ''): string
    {
        $string = preg_replace('/[^a-zA-Z0-9\*\-:]/', '_', $string);

        return $string;
    }

    /**
     * getUserThemeMode returns the users configured Theme Mode.
     * Since we cannot handle the 'system' setting (it's client-side),
     * we default to 'dark'.
     *
     * @param User $user
     * @return string
     */
    public static function getUserThemeMode(User $user): string
    {
        $mode = 'dark';

        if (isset($user)) {
            $mode = $user->getPreferences()->getValue('icingaweb', 'theme_mode', $mode);
        }

        // Could be system, which we cannot handle since it's browser-side
        if (!in_array($mode, ['dark', 'light'])) {
            $mode = 'dark';
        }

        return $mode;
    }
}
