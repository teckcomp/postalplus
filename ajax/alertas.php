<?php

/**
 * Postal+ — alertas em tela (Bloco 7). GET: alertas pendentes do usuário (canal "tela", não lidos,
 * objetos das entidades da sessão, perfil permitido na Configuração). POST lido=<id|todos>: marca lido.
 *
 * Carregado em todas as páginas do GLPI por js/alertas.js (hook add_javascript); sem direito de
 * leitura do plugin responde 403 e o JS para de consultar.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use GlpiPlugin\Postalplus\Alertas;
use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\PerfilDireitos;

header('Content-Type: application/json; charset=UTF-8');

if (!Session::getLoginUserID() || !Session::haveRight(PerfilDireitos::RIGHT_OBJETO, READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'parar' => true]);
    return;
}

$cfg = Configuracao::lerCru();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lido = (string) ($_POST['lido'] ?? '');
    $n    = $lido === 'todos' ? Alertas::marcarTodosLidos($cfg) : Alertas::marcarLido((int) $lido);
    echo json_encode(['ok' => true, 'lidos' => $n, 'csrf' => Session::getNewCSRFToken()]);
    return;
}

if (!Alertas::perfilPermitido($cfg)) {
    echo json_encode(['ok' => true, 'parar' => true, 'alertas' => []]);
    return;
}

echo json_encode([
    'ok'        => true,
    'intervalo' => max(15, (int) ($cfg['toast_intervalo'] ?? 45)),
    'alertas'   => Alertas::pendentes($cfg),
], JSON_UNESCAPED_UNICODE);
