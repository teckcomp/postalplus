<?php

/**
 * Postal+ — Detalhe do objeto (linha do tempo, prazo de retirada, abas).
 *
 * Objeto cadastrado: dados gravados + linha do tempo com os eventos da API Rastro (Bloco 3), botão
 * "Consultar agora" e Excluir (direito DELETE). Códigos da demonstração mostram o mockup até o Bloco 6.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Demo;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Situacao;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, READ);

$nav = Menu::nav('painel');

// Excluir (de vez, levando eventos e alertas) — só objeto real, só com direito DELETE.
if (isset($_POST['excluir'])) {
    Session::checkRight(PerfilDireitos::RIGHT_OBJETO, DELETE);
    $alvo = new Objeto();
    $id   = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && $alvo->getFromDB($id) && $alvo->canViewItem()) {
        $codigo = (string) $alvo->fields['codigo'];
        $alvo->delete(['id' => $id], true);
        Configuracao::log("objeto $codigo (id $id) excluido por " . Session::getLoginUserID());
        Session::addMessageAfterRedirect(htmlescape("Objeto $codigo excluído."), false, INFO);
    } else {
        Session::addMessageAfterRedirect(htmlescape('Objeto não encontrado.'), false, ERROR);
    }
    Html::redirect($nav['web'] . '/front/painel.php');
}

$codigo = Objeto::normalizarCodigo((string) ($_GET['codigo'] ?? ''));
$objeto = null;
$real   = false;
if (Situacao::codigoValido($codigo)) {
    $item = Objeto::buscarVisivel($codigo);
    if ($item) {
        $objeto            = Objeto::paraTela($item->fields);
        $objeto['eventos'] = Objeto::eventosParaTela((int) $item->getID());
        $real              = true;
    } else {
        $objeto = Demo::objeto($codigo);
        if ($objeto) {
            // Demonstração: entidade/responsável da sessão, como no Bloco 1b.
            $objeto['entidade']    = (string) Dropdown::getDropdownName('glpi_entities', (int) ($_SESSION['glpiactive_entity'] ?? 0));
            $objeto['responsavel'] = (string) ($_SESSION['glpiname'] ?? '');
        }
    }
}

Html::header('Postal+ · ' . ($objeto ? $codigo : 'Objeto'), '', 'tools', Menu::class, 'painel');

TemplateRenderer::getInstance()->display('@postalplus/objeto.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'codigo'       => $codigo,
        'objeto'       => $objeto,
        'real'         => $real,
        'pode_excluir' => $real && Session::haveRight(PerfilDireitos::RIGHT_OBJETO, DELETE),
        'url_self'     => $nav['web'] . '/front/objeto.php',
        'url_consultar'=> $nav['web'] . '/ajax/consultar.php',
        'url_painel'   => $nav['web'] . '/front/painel.php',
        'url_ticket'   => Ticket::getFormURL() . '?id=',
    ],
]);

Html::footer();
