<?php

/**
 * Postal+ — Adicionar objetos (individual e em lote).
 *
 * Bloco 1b: tela navegável; validação do código e classificação do lote funcionam no navegador,
 * mas NADA é gravado (salvar = Bloco 2, importação = Bloco 10).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Demo;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\PerfilDireitos;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, CREATE);

/** @var \DBmysql $DB */
global $DB;

$nav = Menu::nav('adicionar');

// Códigos já cadastrados = tabela real + demonstração (para a classificação do lote).
$codigos = Demo::codigos();
foreach ($DB->request(['SELECT' => ['codigo'], 'FROM' => 'glpi_plugin_postalplus_objetos', 'WHERE' => ['is_deleted' => 0]]) as $r) {
    $codigos[] = (string) $r['codigo'];
}

$entidades = [];
foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $id) {
    $entidades[] = ['id' => (int) $id, 'nome' => (string) Dropdown::getDropdownName('glpi_entities', (int) $id)];
}

$grupos = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1], 'ORDER' => 'completename', 'LIMIT' => 200]) as $g) {
    $grupos[] = ['id' => (int) $g['id'], 'nome' => (string) $g['completename']];
}

Html::header('Postal+ · Adicionar objetos', '', 'tools', Menu::class, 'adicionar');

TemplateRenderer::getInstance()->display('@postalplus/adicionar.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'entidades'       => $entidades,
        'entidade_atual'  => (int) ($_SESSION['glpiactive_entity'] ?? 0),
        'usuario'         => ['id' => (int) Session::getLoginUserID(), 'nome' => (string) ($_SESSION['glpiname'] ?? '')],
        'grupos'          => $grupos,
        'servicos'        => ['PAC Contrato', 'SEDEX Contrato', 'SEDEX 10 Contrato', 'Carta Registrada'],
        'codigos_json'    => json_encode(array_values(array_unique($codigos)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ],
]);

Html::footer();
