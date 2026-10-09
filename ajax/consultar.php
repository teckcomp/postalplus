<?php

/**
 * Postal+ — "Consultar agora" (AJAX, POST): um objeto (codigo=...) ou todos os visíveis em acompanhamento (todos=1).
 *
 * Exige leitura de objetos (atualizar o rastreio de quem você já enxerga não muda dados de cadastro).
 * CSRF: cabeçalho X-Glpi-Csrf-Token (lição 16). Resposta JSON com "csrf" novo.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Rastreio;

header('Content-Type: application/json; charset=UTF-8');

$responder = static function (int $http, array $dados): void {
    http_response_code($http);
    $dados['csrf'] = Session::getNewCSRFToken();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $responder(405, ['ok' => false, 'mensagem' => 'Use POST.']);
    return;
}
if (!Session::haveRight(PerfilDireitos::RIGHT_OBJETO, READ)) {
    $responder(403, ['ok' => false, 'mensagem' => 'Sem direito de ver objetos do Postal+.']);
    return;
}

if (!empty($_POST['todos'])) {
    $objetos = Objeto::listarParaConsulta(Rastreio::LIMITE_MANUAL);
} else {
    $codigo = Objeto::normalizarCodigo((string) ($_POST['codigo'] ?? ''));
    $item   = $codigo !== '' ? Objeto::buscarVisivel($codigo) : null;
    if ($item === null) {
        $responder(404, ['ok' => false, 'mensagem' => 'Objeto não encontrado.']);
        return;
    }
    $objetos = [$item->fields];
}

@set_time_limit(180);

try {
    $r = Rastreio::doGlpi()->consultar($objetos, 'manual');
} catch (\Throwable $e) {
    Configuracao::log('Consultar agora: exceção ' . $e::class . ' ' . $e->getMessage());
    $responder(500, ['ok' => false, 'mensagem' => 'Erro interno na consulta (ver files/_log/postalplus.log).']);
    return;
}

$responder(200, [
    'ok'           => $r['ok'],
    'interrompida' => $r['interrompida'],
    'mensagem' => $r['mensagem'],
    'objetos'  => $r['objetos'],
    'novos'    => $r['novos'],
    'erros'    => $r['erros'],
    'itens'    => $r['itens'],
]);
