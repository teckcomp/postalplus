<?php

/**
 * Postal+ — rastreio de envios dos Correios (API Rastro / CWS) para GLPI 11.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\PerfilDireitos;

define('PLUGIN_POSTALPLUS_VERSION', '0.1.0');
define('PLUGIN_POSTALPLUS_MIN_GLPI', '11.0.0');
define('PLUGIN_POSTALPLUS_MAX_GLPI', '11.0.99');

/** Caminho web do plugin (GLPI 11 serve todo plugin em /plugins/<chave>). */
define('PLUGIN_POSTALPLUS_WEBPATH', '/plugins/postalplus');

function plugin_init_postalplus()
{
    global $PLUGIN_HOOKS;

    // Campos de configuração gravados criptografados pela GLPIKey.
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['postalplus'] = ['cws_codigo_acesso', 'cws_token'];

    if (!Plugin::isPluginActive('postalplus')) {
        return;
    }

    Plugin::registerClass(PerfilDireitos::class, ['addtabon' => \Profile::class]);

    // Menu Ferramentas — Menu::getMenuContent() devolve false sem o direito de leitura.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['postalplus'] = ['tools' => Menu::class];

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['postalplus'] = ['css/postalplus.css'];
}

function plugin_version_postalplus()
{
    return [
        'name'         => 'Postal+',
        'version'      => PLUGIN_POSTALPLUS_VERSION,
        'author'       => '<a href="https://teckcomp.com.br">Teckcomp</a>',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/teckcomp/postalplus',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_POSTALPLUS_MIN_GLPI,
                'max' => PLUGIN_POSTALPLUS_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_postalplus_check_prerequisites()
{
    return true;
}

function plugin_postalplus_check_config($verbose = false)
{
    return true;
}
