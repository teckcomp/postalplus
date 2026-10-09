<?php

/**
 * Postal+ — Painel de rastreio.
 *
 * Bloco 2: objetos cadastrados (tabela real) aparecem primeiro, seguidos dos 9 de DEMONSTRAÇÃO
 * (marcados "demo"). No Bloco 5 a demonstração sai e o painel fica só com a tabela.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Demo;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Situacao;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, READ);

$nav     = Menu::nav('painel');
$objetos = array_merge(array_map([Objeto::class, 'paraTela'], Objeto::listarVisiveis()), Demo::objetos());
foreach ($objetos as &$o) {
    $o['card'] = Situacao::cardDe($o['situacao']);
    unset($o['eventos'], $o['alertas']);
}
unset($o);

Html::header('Postal+ · Painel', '', 'tools', Menu::class, 'painel');

TemplateRenderer::getInstance()->display('@postalplus/painel.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'objetos'    => $objetos,
        'cards'      => Situacao::cards(),
        'totais'     => Situacao::contar($objetos),
        'toasts'     => Demo::toasts(),
        'pode_criar' => Session::haveRight(PerfilDireitos::RIGHT_OBJETO, CREATE),
        'url_objeto' => $nav['web'] . '/front/objeto.php?codigo=',
        'url_add'    => $nav['web'] . '/front/adicionar.php',
        'url_ticket' => Ticket::getFormURL() . '?id=',
    ],
]);

Html::footer();
