<?php

/**
 * Postal+ — Painel de rastreio (Bloco 5: só dados reais).
 *
 * Lista os objetos em acompanhamento e os encerrados (entregues/devolvidos) nos últimos
 * Objeto::DIAS_ENCERRADOS_PAINEL dias. Com ?encerrados=1 mostra também os encerrados há mais tempo;
 * esses não entram nos cards (data-card "antigo"). Sem nenhum objeto cadastrado: convite para Adicionar.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\Monitor;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Rastreio;
use GlpiPlugin\Postalplus\Situacao;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, READ);

$nav        = Menu::nav('painel');
$agora      = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
$comAntigos = !empty($_GET['encerrados']);

$objetos = [];
$recentes = [];
foreach (Objeto::listarPainel($comAntigos, $agora) as $r) {
    $o           = Objeto::paraTela($r);
    $o['antigo'] = Objeto::encerradoAntigo($r, $agora);
    $o['card']   = $o['antigo'] ? 'antigo' : Situacao::cardDe($o['situacao']);
    unset($o['eventos'], $o['alertas']);
    $objetos[] = $o;
    if (!$o['antigo']) {
        $recentes[] = $o;
    }
}
$antigos = Objeto::contarEncerradosAntigos($agora);

Html::header('Postal+ · Painel', '', 'tools', Menu::class, 'painel');

TemplateRenderer::getInstance()->display('@postalplus/painel.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'objetos'       => $objetos,
        'cards'         => Situacao::cards(),
        'totais'        => Situacao::contar($recentes),
        'cadastrados'   => Objeto::contarVisiveis(),
        'antigos'       => $antigos,
        'com_antigos'   => $comAntigos,
        'dias_encerrados' => Objeto::DIAS_ENCERRADOS_PAINEL,
        'url_painel'    => $nav['web'] . '/front/painel.php',
        'pode_criar'    => Session::haveRight(PerfilDireitos::RIGHT_OBJETO, CREATE),
        'url_objeto'    => $nav['web'] . '/front/objeto.php?codigo=',
        'url_add'       => $nav['web'] . '/front/adicionar.php',
        'url_ticket'    => Ticket::getFormURL() . '?id=',
        'url_consultar' => $nav['web'] . '/ajax/consultar.php',
        'consultaveis'  => count(Objeto::listarParaConsulta(Rastreio::LIMITE_MANUAL)),
        'monitor'       => Monitor::estado(),
    ],
]);

Html::footer();
