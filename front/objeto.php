<?php

/**
 * Postal+ — Detalhe do objeto (linha do tempo, prazo de retirada, abas).
 *
 * Bloco 1b: DADOS DE DEMONSTRAÇÃO. No Bloco 6 lê objeto/eventos/alertas reais.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Demo;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Situacao;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, READ);

$nav    = Menu::nav('painel');
$codigo = strtoupper(trim((string) ($_GET['codigo'] ?? '')));
$objeto = Situacao::codigoValido($codigo) ? Demo::objeto($codigo) : null;

Html::header('Postal+ · ' . ($objeto ? $codigo : 'Objeto'), '', 'tools', Menu::class, 'painel');

TemplateRenderer::getInstance()->display('@postalplus/objeto.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'codigo'     => $codigo,
        'objeto'     => $objeto,
        'responsavel'=> (string) ($_SESSION['glpiname'] ?? ''),
        'entidade'   => (string) Dropdown::getDropdownName('glpi_entities', (int) ($_SESSION['glpiactive_entity'] ?? 0)),
        'url_painel' => $nav['web'] . '/front/painel.php',
        'url_ticket' => Ticket::getFormURL() . '?id=',
    ],
]);

Html::footer();
