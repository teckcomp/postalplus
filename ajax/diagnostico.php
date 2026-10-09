<?php

/**
 * Postal+ — "Testar rastreio de um código" (Bloco 11, AJAX POST codigo=). Consulta a API Rastro com as
 * credenciais salvas e devolve a resposta crua (objetos[0], sem token) e a classificação do plugin para
 * cada evento. Não grava nada. Exige config UPDATE.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Cws\Cliente;
use GlpiPlugin\Postalplus\Cws\CwsErro;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Rastreio;
use GlpiPlugin\Postalplus\Situacao;

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

$codigo = Objeto::normalizarCodigo((string) ($_POST['codigo'] ?? ''));
$saida  = ['ok' => false, 'codigo' => $codigo, 'simulado' => Cliente::simulado(), 'erro' => null, 'bruto' => null, 'eventos' => [], 'situacao' => null];

if (!Situacao::codigoValido($codigo)) {
    $saida['erro'] = 'Código inválido: 2 letras + 9 dígitos + 2 letras.';
} else {
    try {
        $resposta       = Cliente::doGlpi()->rastrear($codigo);
        $saida['bruto'] = $resposta;
        $eventos        = [];
        foreach ((array) ($resposta['eventos'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $ev    = Rastreio::normalizarEvento($e);
            $class = Situacao::classificarEvento($ev['codigo'], $ev['tipo'], $ev['descricao']);
            $eventos[] = [
                'data'      => $ev['data_evento'],
                'codigo'    => $ev['codigo'],
                'tipo'      => $ev['tipo'],
                'descricao' => $ev['descricao'],
                'local'     => Rastreio::localDoEvento($ev),
                'situacao'  => $class['situacao'],
                'rotulo'    => $class['rotulo'],
                'critico'   => $class['critico'],
            ];
        }
        usort($eventos, static fn($a, $b) => strcmp((string) $b['data'], (string) $a['data']));
        $saida['eventos']  = $eventos;
        $saida['situacao'] = $eventos !== [] ? $eventos[0]['rotulo'] . ' (' . $eventos[0]['situacao'] . ')' . (Situacao::encerraAcompanhamento($eventos[0]) ? ' · encerra o acompanhamento' : '') : null;
        $saida['ok']       = $eventos !== [];
        if ($eventos === []) {
            $saida['erro'] = trim(strip_tags((string) ($resposta['mensagem'] ?? ''))) ?: 'A API não devolveu eventos para este código.';
        }
    } catch (CwsErro $e) {
        $saida['erro'] = $e->getMessage() . ' [' . $e->tipo . ($e->status ? ', HTTP ' . $e->status : '') . ']';
    } catch (\Throwable $e) {
        Configuracao::log('diagnostico: exceção ' . $e::class . ' ' . $e->getMessage());
        $saida['erro'] = 'Erro interno (ver files/_log/postalplus.log).';
    }
}
Configuracao::log("diagnostico $codigo: " . ($saida['ok'] ? count($saida['eventos']) . ' evento(s)' : 'falha: ' . $saida['erro']));

$saida['csrf'] = Session::getNewCSRFToken();
echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
