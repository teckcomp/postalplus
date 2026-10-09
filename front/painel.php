<?php

/**
 * Postal+ — Painel (Bloco 0: página provisória com diagnóstico da instalação).
 *
 * GLPI 11: este script roda dentro do kernel já iniciado (não incluir inc/includes.php).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Install;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\PerfilDireitos;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, READ);

/** @var \DBmysql $DB */
global $DB;

$tabelas = [];
foreach (Install::TABELAS as $tabela) {
    $existe = $DB->tableExists($tabela);
    $linhas = 0;
    if ($existe) {
        $row    = $DB->request(['COUNT' => 'cpt', 'FROM' => $tabela])->current();
        $linhas = (int) ($row['cpt'] ?? 0);
    }
    $tabelas[] = ['nome' => $tabela, 'existe' => $existe, 'linhas' => $linhas];
}

$config = Config::getConfigurationValues(Install::CONFIG_CONTEXT);

$info = [
    'versao'      => PLUGIN_POSTALPLUS_VERSION,
    'tabelas'     => $tabelas,
    'tabelas_ok'  => count(array_filter($tabelas, static fn($t) => $t['existe'])) === count($tabelas),
    'ambiente'    => ($config['ambiente'] ?? '') === 'producao' ? 'Produção' : 'Homologação (cwshom)',
    'config_qtd'  => count($config),
    'credenciais' => ($config['cws_usuario'] ?? '') !== '' && ($config['cws_codigo_acesso'] ?? '') !== '',
    'direitos'    => [
        'objeto_ler'     => Session::haveRight(PerfilDireitos::RIGHT_OBJETO, READ),
        'objeto_criar'   => Session::haveRight(PerfilDireitos::RIGHT_OBJETO, CREATE),
        'config_ler'     => Session::haveRight(PerfilDireitos::RIGHT_CONFIG, READ),
        'config_alterar' => Session::haveRight(PerfilDireitos::RIGHT_CONFIG, UPDATE),
    ],
];

Html::header('Postal+', '', 'tools', Menu::class, 'painel');

TemplateRenderer::getInstance()->display('@postalplus/painel.html.twig', [
    'info' => $info,
]);

Html::footer();
