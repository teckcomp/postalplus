<?php

/**
 * Postal+ — tela de Configuração (credenciais CWS, frequência, notificações).
 *
 * GLPI 11: roda com o kernel já iniciado (não incluir inc/includes.php). CSRF validado pelo core.
 * READ vê a tela; UPDATE salva e testa a conexão.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Cws\Cliente;
use GlpiPlugin\Postalplus\Install;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\PerfilDireitos;

Session::checkRight(PerfilDireitos::RIGHT_CONFIG, READ);

/** @var \DBmysql $DB */
global $DB;

$nav = Menu::nav('config');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight(PerfilDireitos::RIGHT_CONFIG, UPDATE);

    $r = Configuracao::salvar($_POST);
    if ($r['ok']) {
        Session::addMessageAfterRedirect('Configuração do Postal+ salva.', false, INFO);
        if ($r['token_descartado']) {
            Session::addMessageAfterRedirect('Credenciais ou ambiente mudaram: o token anterior foi descartado. Use "Testar conexão" para gerar outro.', false, WARNING);
        }
    } else {
        foreach ($r['erros'] as $erro) {
            Session::addMessageAfterRedirect($erro, false, ERROR);
        }
    }
    Html::redirect($nav['web'] . '/front/config.php');
}

// Perfis de interface padrão (central) para "Alertas em tela".
$perfis = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'central'], 'ORDER' => 'name']) as $p) {
    $perfis[] = ['id' => (int) $p['id'], 'nome' => (string) $p['name']];
}

// Últimas execuções da consulta (preenchidas a partir do Bloco 4).
$execucoes = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_postalplus_consultas', 'ORDER' => 'date_start DESC', 'LIMIT' => 10]) as $e) {
    $execucoes[] = [
        'horario'   => (string) $e['date_start'],
        'origem'    => (string) $e['origem'],
        'objetos'   => (int) $e['objetos'],
        'novos'     => (int) $e['novos_eventos'],
        'erros'     => (int) $e['erros'],
        'resultado' => (string) $e['resultado'],
    ];
}

// Diagnóstico da instalação (veio do painel provisório do Bloco 0).
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

Html::header('Postal+ · Configuração', '', 'tools', Menu::class, 'config');

TemplateRenderer::getInstance()->display('@postalplus/config.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'cfg'          => Configuracao::paraTela(),
        'ambientes'    => Configuracao::AMBIENTES,
        'frequencias'  => Configuracao::FREQUENCIAS,
        'chaves_freq'  => Configuracao::CHAVES_FREQUENCIA,
        'perfis'       => $perfis,
        'execucoes'    => $execucoes,
        'tabelas'      => $tabelas,
        'versao'       => PLUGIN_POSTALPLUS_VERSION,
        'pode_alterar' => Session::haveRight(PerfilDireitos::RIGHT_CONFIG, UPDATE),
        'simulado'     => Cliente::simulado(),
        'url_testar'   => $nav['web'] . '/ajax/testar_conexao.php',
    ],
]);

Html::footer();
