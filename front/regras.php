<?php

/**
 * Postal+ — Regras de alerta (FUNCIONAL desde o Bloco 1b).
 *
 * Edita as 5 regras fixas e o padrão do chamado automático (categoria, grupo, prioridade).
 * READ vê; UPDATE salva. O motor que dispara os alertas entra no Bloco 7.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Regras;

Session::checkRight(PerfilDireitos::RIGHT_CONFIG, READ);

/** @var \DBmysql $DB */
global $DB;

$nav = Menu::nav('regras');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight(PerfilDireitos::RIGHT_CONFIG, UPDATE);

    $erros = array_merge(Configuracao::validarChamado($_POST), Regras::validar($_POST));
    if ($erros === []) {
        Regras::salvar($_POST);
        Configuracao::salvarChamado($_POST);
        Session::addMessageAfterRedirect('Regras de alerta salvas.', false, INFO);
    } else {
        foreach ($erros as $erro) {
            Session::addMessageAfterRedirect($erro, false, ERROR);
        }
    }
    Html::redirect($nav['web'] . '/front/regras.php');
}

$cfg = Configuracao::lerCru();

$categorias = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['is_incident' => 1], 'ORDER' => 'completename', 'LIMIT' => 500]) as $c) {
    $categorias[] = ['id' => (int) $c['id'], 'nome' => (string) $c['completename']];
}
$grupos = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1], 'ORDER' => 'completename', 'LIMIT' => 500]) as $g) {
    $grupos[] = ['id' => (int) $g['id'], 'nome' => (string) $g['completename']];
}
$prioridades = [];
foreach ([1, 2, 3, 4, 5, 6] as $p) {
    $prioridades[$p] = (string) Ticket::getPriorityName($p);
}

Html::header('Postal+ · Regras de alerta', '', 'tools', Menu::class, 'regras');

TemplateRenderer::getInstance()->display('@postalplus/regras.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'regras'       => Regras::listar(),
        'eventos'      => Regras::EVENTOS_CRITICOS,
        'categorias'   => $categorias,
        'grupos'       => $grupos,
        'prioridades'  => $prioridades,
        'chamado'      => [
            'categoria'  => (int) $cfg['chamado_itilcategories_id'],
            'grupo'      => (int) $cfg['chamado_groups_id'],
            'prioridade' => (int) $cfg['chamado_prioridade'],
        ],
        'pode_alterar' => Session::haveRight(PerfilDireitos::RIGHT_CONFIG, UPDATE),
        'url_config'   => $nav['web'] . '/front/config.php',
    ],
]);

Html::footer();
