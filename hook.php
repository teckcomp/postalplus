<?php

/**
 * Postal+ — instalação e desinstalação.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use GlpiPlugin\Postalplus\Install;

function plugin_postalplus_install()
{
    $migration = new Migration(PLUGIN_POSTALPLUS_VERSION);
    Install::install($migration);
    $migration->executeMigration();

    return true;
}

function plugin_postalplus_uninstall()
{
    Install::uninstall();

    return true;
}
