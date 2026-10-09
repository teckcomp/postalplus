<?php

/**
 * Postal+ — Detalhe do objeto (linha do tempo, prazo de retirada, abas).
 *
 * Bloco 6: só dados reais. Código inexistente, inválido ou de entidade sem acesso → "Objeto não
 * encontrado". Abas: Rastreamento (eventos da API), Alertas, Chamados (vinculado + abertos por alerta),
 * Documentos (os do chamado vinculado) e Histórico (classe Detalhe). Excluir exige DELETE.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Chamados;
use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Detalhe;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Situacao;

/** @var array $CFG_GLPI */
global $CFG_GLPI;
/** @var \DBmysql $DB */
global $DB;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, READ);

$nav = Menu::nav('painel');

// Excluir (de vez, levando eventos e alertas) — só com direito DELETE.
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

// Registrar reclamação feita nos Correios (Bloco 8): vira alerta no objeto e acompanhamento no chamado vinculado.
if (isset($_POST['reclamacao'])) {
    Session::checkRight(PerfilDireitos::RIGHT_OBJETO, UPDATE);
    $alvo = new Objeto();
    $id   = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && $alvo->getFromDB($id) && $alvo->canViewItem()) {
        $protocolo = mb_substr(trim((string) ($_POST['protocolo'] ?? '')), 0, 60);
        $obs       = mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 2000);
        $texto     = 'Reclamação registrada nos Correios' . ($protocolo !== '' ? " · protocolo $protocolo" : '') . ' por ' . ($_SESSION['glpiname'] ?? '') . ($obs !== '' ? ".\n$obs" : '.');
        $canais    = [];
        $ticketId  = 0;
        $ticket    = Chamados::vinculado($alvo->fields);
        if ($ticket !== null) {
            $res = Chamados::acompanhar($ticket, "Postal+ · Reclamação nos Correios · {$alvo->fields['codigo']}\n$texto");
            if ($res['ok']) {
                $canais[] = 'chamado';
                $ticketId = $res['tickets_id'];
            }
        }
        $DB->insert('glpi_plugin_postalplus_alertas', [
            'plugin_postalplus_objetos_id' => $id,
            'regra'                        => 'reclamacao',
            'chave'                        => 'reclamacao:' . time(),
            'nivel'                        => 'info',
            'titulo'                       => 'Reclamação registrada nos Correios',
            'mensagem'                     => str_replace("\n", ' ', $texto),
            'canais'                       => implode(',', $canais),
            'tickets_id'                   => $ticketId,
            'date_creation'                => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        Configuracao::log("reclamacao registrada para {$alvo->fields['codigo']} por " . Session::getLoginUserID());
        Session::addMessageAfterRedirect(htmlescape('Reclamação registrada no objeto' . ($ticketId > 0 ? " e no chamado #$ticketId" : '') . '.'), false, INFO);
        Html::redirect($nav['web'] . '/front/objeto.php?codigo=' . rawurlencode((string) $alvo->fields['codigo']));
    }
    Session::addMessageAfterRedirect(htmlescape('Objeto não encontrado.'), false, ERROR);
    Html::redirect($nav['web'] . '/front/painel.php');
}

$codigo = Objeto::normalizarCodigo((string) ($_GET['codigo'] ?? ''));
$item   = Situacao::codigoValido($codigo) ? Objeto::buscarVisivel($codigo) : null;

$objeto = null;
$abas   = null;
if ($item) {
    $id                = (int) $item->getID();
    $alertasCrus       = Detalhe::alertasCrus($id);
    $objeto            = Objeto::paraTela($item->fields);
    $objeto['eventos'] = Objeto::eventosParaTela($id);
    $objeto['alertas'] = Detalhe::alertasParaTela($alertasCrus);
    $abas = [
        'chamados'   => Detalhe::chamados((int) $item->fields['tickets_id'], $alertasCrus),
        'documentos' => Detalhe::documentos((int) $item->fields['tickets_id'], (string) $CFG_GLPI['root_doc']),
        'historico'  => Detalhe::historico($item->fields, $alertasCrus),
    ];
}

Html::header('Postal+ · ' . ($objeto ? $codigo : 'Objeto não encontrado'), '', 'tools', Menu::class, 'painel');

TemplateRenderer::getInstance()->display('@postalplus/objeto.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'codigo'        => $codigo,
        'codigo_valido' => Situacao::codigoValido($codigo),
        'objeto'        => $objeto,
        'abas'          => $abas,
        'pode_excluir'  => $objeto !== null && Session::haveRight(PerfilDireitos::RIGHT_OBJETO, DELETE),
        'pode_alterar'  => $objeto !== null && Session::haveRight(PerfilDireitos::RIGHT_OBJETO, UPDATE),
        'url_reclamacao_correios' => 'https://www.correios.com.br/falecomoscorreios',
        'url_self'      => $nav['web'] . '/front/objeto.php',
        'url_consultar' => $nav['web'] . '/ajax/consultar.php',
        'url_painel'    => $nav['web'] . '/front/painel.php',
        'url_ticket'    => Ticket::getFormURL() . '?id=',
    ],
]);

Html::footer();
