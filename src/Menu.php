<?php

/**
 * Postal+ — entrada no menu Ferramentas.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use CommonGLPI;
use Session;

class Menu extends CommonGLPI
{
    public static $rightname = PerfilDireitos::RIGHT_OBJETO;

    public static function getTypeName($nb = 0)
    {
        return 'Postal+';
    }

    public static function getMenuName()
    {
        return 'Postal+';
    }

    public static function getIcon()
    {
        return 'ti ti-truck-delivery';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(PerfilDireitos::RIGHT_OBJETO, READ)) {
            return false;
        }

        $base = PLUGIN_POSTALPLUS_WEBPATH . '/front';

        $menu = [
            'title' => self::getMenuName(),
            'page'  => $base . '/painel.php',
            'icon'  => self::getIcon(),
        ];

        // Sub-entradas (breadcrumb). As demais telas entram nos blocos seguintes.
        $menu['options'] = [
            'painel' => [
                'title' => 'Painel',
                'page'  => $base . '/painel.php',
                'icon'  => 'ti ti-layout-dashboard',
            ],
        ];

        return $menu;
    }
}
