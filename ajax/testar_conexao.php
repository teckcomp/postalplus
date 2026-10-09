<?php

/**
 * Postal+ — "Testar conexão" (AJAX, POST). Gera token novo com as credenciais SALVAS e sonda a API Rastro.
 *
 * CSRF: o core valida o cabeçalho X-Glpi-Csrf-Token (token preservado em AJAX).
 * Resposta JSON sem token nem credenciais; inclui "csrf" novo por convenção do projeto.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use GlpiPlugin\Postalplus\Cws\Cliente;
use GlpiPlugin\Postalplus\PerfilDireitos;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Use POST.']);
    return;
}

if (!Session::haveRight(PerfilDireitos::RIGHT_CONFIG, UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem direito de alterar a configuração do Postal+.']);
    return;
}

try {
    $resultado = Cliente::doGlpi()->testarConexao();
} catch (\Throwable $e) {
    \GlpiPlugin\Postalplus\Configuracao::log('Testar conexão: exceção ' . $e::class);
    $resultado = ['ok' => false, 'erro' => 'Erro interno ao testar a conexão (ver files/_log/postalplus.log).'];
}

$resultado['csrf'] = Session::getNewCSRFToken();

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
