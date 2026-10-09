<?php

/**
 * Postal+ — entrada no menu Ferramentas e navegação interna das telas.
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

    /**
     * Telas do plugin: chave => [título, arquivo em front/, ícone, direito, nível].
     *
     * @return array<string,array{0:string,1:string,2:string,3:string,4:int}>
     */
    public static function telas(): array
    {
        return [
            'painel' => ['Painel', 'painel.php', 'ti ti-layout-dashboard', PerfilDireitos::RIGHT_OBJETO, READ],
            'config' => ['Configuração', 'config.php', 'ti ti-settings', PerfilDireitos::RIGHT_CONFIG, READ],
        ];
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(PerfilDireitos::RIGHT_OBJETO, READ)) {
            return false;
        }

        $base = PLUGIN_POSTALPLUS_WEBPATH . '/front';

        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $base . '/painel.php',
            'icon'    => self::getIcon(),
            'options' => [],
        ];

        foreach (self::telas() as $chave => [$titulo, $arquivo, $icone, $direito, $nivel]) {
            if (!Session::haveRight($direito, $nivel)) {
                continue;
            }
            $menu['options'][$chave] = [
                'title' => $titulo,
                'page'  => $base . '/' . $arquivo,
                'icon'  => $icone,
            ];
        }

        return $menu;
    }

    /**
     * Dados da barra de navegação interna (partial _nav.html.twig).
     *
     * @return array{web:string, ativo:string, v:string, itens:list<array{chave:string,titulo:string,url:string,icone:string}>}
     */
    public static function nav(string $ativo): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $web   = ($CFG_GLPI['root_doc'] ?? '') . PLUGIN_POSTALPLUS_WEBPATH;
        $itens = [];
        foreach (self::telas() as $chave => [$titulo, $arquivo, $icone, $direito, $nivel]) {
            if (Session::haveRight($direito, $nivel)) {
                $itens[] = ['chave' => $chave, 'titulo' => $titulo, 'url' => $web . '/front/' . $arquivo, 'icone' => $icone];
            }
        }

        // Versão dos estáticos para furar cache do navegador: maior mtime de public/js.
        $v = 0;
        foreach (glob(dirname(__DIR__) . '/public/js/*.js') ?: [] as $arq) {
            $v = max($v, (int) filemtime($arq));
        }

        return ['web' => $web, 'ativo' => $ativo, 'v' => (string) $v, 'itens' => $itens];
    }
}
