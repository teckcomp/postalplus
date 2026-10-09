<?php

/**
 * Postal+ — consulta objetos na API Rastro e grava o resultado (Bloco 3).
 *
 * Para cada objeto: pede os eventos, grava só os novos em glpi_plugin_postalplus_eventos (hash único
 * por objeto), recalcula situação / último evento / prazos no objeto e registra a execução em
 * glpi_plugin_postalplus_consultas. Usado por "Consultar agora" (manual), pelo cadastro e, no
 * Bloco 4, pela ação automática.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use GlpiPlugin\Postalplus\Cws\Cliente;
use GlpiPlugin\Postalplus\Cws\CwsErro;

class Rastreio
{
    /** Máximo de objetos por "Consultar agora" do painel (o cron do Bloco 4 trabalha em lote). */
    public const LIMITE_MANUAL = 50;

    public function __construct(private Cliente $cliente)
    {
    }

    public static function doGlpi(): self
    {
        return new self(Cliente::doGlpi());
    }

    /**
     * Consulta uma lista de objetos (linhas da tabela) e registra a execução.
     *
     * @param list<array<string,mixed>> $objetos
     * @return array{ok:bool, interrompida:bool, objetos:int, novos:int, erros:int, mensagem:string, itens:list<array<string,mixed>>}
     */
    public function consultar(array $objetos, string $origem): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $inicio = self::agora();
        $itens  = [];
        $novos  = 0;
        $erros  = 0;
        $fatal  = null;

        foreach ($objetos as $obj) {
            if ($fatal !== null) {
                $itens[] = ['codigo' => (string) $obj['codigo'], 'ok' => false, 'novos' => 0, 'erro' => $fatal];
                $erros++;
                continue;
            }
            try {
                $item = $this->consultarUm($obj);
            } catch (CwsErro $e) {
                $item = ['codigo' => (string) $obj['codigo'], 'ok' => false, 'novos' => 0, 'erro' => $e->getMessage()];
                $this->gravarErro((int) $obj['id'], $e->getMessage());
                // Falha de credencial/token/rede vale para todos: não insiste nos demais.
                if (in_array($e->tipo, ['credenciais', 'token', 'rede'], true) || in_array($e->status, [401, 403], true)) {
                    $fatal = $e->getMessage();
                }
            }
            $itens[] = $item;
            $novos  += $item['novos'];
            $erros  += $item['ok'] ? 0 : 1;
        }

        $n         = count($objetos);
        $resultado = match (true) {
            $n === 0     => 'Nenhum objeto para consultar',
            $fatal !== null => mb_substr('Falhou: ' . $fatal, 0, 255),
            $erros > 0   => "Concluída com $erros erro(s)",
            default      => 'Concluída',
        };

        $DB->insert('glpi_plugin_postalplus_consultas', [
            'origem'        => mb_substr($origem, 0, 20),
            'date_start'    => $inicio,
            'date_end'      => self::agora(),
            'objetos'       => $n,
            'novos_eventos' => $novos,
            'alertas'       => 0,
            'erros'         => $erros,
            'resultado'     => $resultado,
            'detalhe'       => json_encode(array_values(array_filter($itens, static fn($i) => !$i['ok'])), JSON_UNESCAPED_UNICODE),
        ]);
        Configuracao::log("consulta $origem: $n objeto(s), $novos evento(s) novo(s), $erros erro(s)" . ($fatal !== null ? ' [interrompida]' : ''));

        return [
            'ok'           => $n > 0 && $erros === 0,
            'interrompida' => $fatal !== null,
            'objetos'      => $n,
            'novos'    => $novos,
            'erros'    => $erros,
            'mensagem' => self::resumo($n, $novos, $erros, $fatal),
            'itens'    => $itens,
        ];
    }

    public static function resumo(int $n, int $novos, int $erros, ?string $fatal): string
    {
        if ($n === 0) {
            return 'Nenhum objeto em acompanhamento para consultar.';
        }
        if ($fatal !== null) {
            return "Consulta interrompida: $fatal";
        }
        $txt = $n === 1 ? '1 objeto consultado' : "$n objetos consultados";
        $txt .= $novos === 1 ? ', 1 evento novo' : ", $novos eventos novos";
        if ($erros > 0) {
            $txt .= $erros === 1 ? ', 1 com erro' : ", $erros com erro";
        }

        return $txt . '.';
    }

    /**
     * @param array<string,mixed> $obj linha de glpi_plugin_postalplus_objetos
     * @return array{codigo:string, ok:bool, novos:int, erro:?string, situacao?:string, rotulo?:string}
     * @throws CwsErro
     */
    public function consultarUm(array $obj): array
    {
        $codigo   = (string) $obj['codigo'];
        $resposta = $this->cliente->rastrear($codigo);

        $eventos = [];
        foreach ((array) ($resposta['eventos'] ?? []) as $e) {
            if (is_array($e)) {
                $eventos[] = self::normalizarEvento($e);
            }
        }

        if ($eventos === []) {
            $msg = trim(strip_tags((string) ($resposta['mensagem'] ?? '')));
            $msg = $msg !== '' ? mb_substr($msg, 0, 255) : 'Os Correios ainda não têm eventos para este objeto.';
            $this->gravarErro((int) $obj['id'], $msg);
            return ['codigo' => $codigo, 'ok' => false, 'novos' => 0, 'erro' => $msg];
        }

        $novos = $this->gravarEventos((int) $obj['id'], $eventos);
        $class = $this->atualizarObjeto($obj, $eventos, $resposta);

        return ['codigo' => $codigo, 'ok' => true, 'novos' => $novos, 'erro' => null] + $class;
    }

    /**
     * Evento da API Rastro => colunas de glpi_plugin_postalplus_eventos (+ hash).
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    public static function normalizarEvento(array $e): array
    {
        $u = (array) ($e['unidade'] ?? []);
        $d = (array) ($e['unidadeDestino'] ?? []);

        $ev = [
            'codigo'         => mb_substr((string) ($e['codigo'] ?? ''), 0, 10),
            'tipo'           => mb_substr((string) ($e['tipo'] ?? ''), 0, 10),
            'descricao'      => mb_substr(trim((string) ($e['descricao'] ?? '')), 0, 255),
            'detalhe'        => trim((string) ($e['detalhe'] ?? '')) ?: null,
            'unidade_nome'   => self::nomeUnidade($u),
            'unidade_cidade' => self::campoEndereco($u, 'cidade'),
            'unidade_uf'     => self::campoEndereco($u, 'uf', 2),
            'destino_nome'   => $d !== [] ? self::nomeUnidade($d) : null,
            'destino_cidade' => $d !== [] ? self::campoEndereco($d, 'cidade') : null,
            'destino_uf'     => $d !== [] ? self::campoEndereco($d, 'uf', 2) : null,
            'data_evento'    => self::data((string) ($e['dtHrCriado'] ?? '')),
        ];
        $ev['hash'] = sha1(implode('|', [$ev['codigo'], $ev['tipo'], $ev['data_evento'], $ev['descricao'], $ev['unidade_cidade']]));

        return $ev;
    }

    /** "Agência dos Correios · LONDRINA - PR" ou "de Unidade de Tratamento, CURITIBA - PR para Unidade de Distribuição, LONDRINA - PR". */
    public static function localDoEvento(array $ev): string
    {
        $origem = self::juntar((string) ($ev['unidade_cidade'] ?? ''), (string) ($ev['unidade_uf'] ?? ''));
        if (!empty($ev['destino_cidade'])) {
            $destino = self::juntar((string) $ev['destino_cidade'], (string) ($ev['destino_uf'] ?? ''));
            return 'de ' . trim(($ev['unidade_nome'] ?? '') . ', ' . $origem, ', ')
                . ' para ' . trim(($ev['destino_nome'] ?? '') . ', ' . $destino, ', ');
        }

        return trim(($ev['unidade_nome'] ?? '') . ($origem !== '' ? ' · ' . $origem : ''), ' ·');
    }

    /**
     * @param list<array<string,mixed>> $eventos
     */
    private function gravarEventos(int $objetoId, array $eventos): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $existentes = [];
        foreach ($DB->request(['SELECT' => ['hash'], 'FROM' => 'glpi_plugin_postalplus_eventos', 'WHERE' => ['plugin_postalplus_objetos_id' => $objetoId]]) as $r) {
            $existentes[$r['hash']] = true;
        }

        $novos = 0;
        foreach ($eventos as $ev) {
            if (isset($existentes[$ev['hash']])) {
                continue;
            }
            $DB->insert('glpi_plugin_postalplus_eventos', $ev + [
                'plugin_postalplus_objetos_id' => $objetoId,
                'date_creation'                => self::agora(),
            ]);
            $existentes[$ev['hash']] = true;
            $novos++;
        }

        return $novos;
    }

    /**
     * Situação, último evento e prazos a partir do evento mais recente.
     *
     * @param array<string,mixed>       $obj
     * @param list<array<string,mixed>> $eventos
     * @param array<string,mixed>       $resposta
     * @return array{situacao:string, rotulo:string}
     */
    private function atualizarObjeto(array $obj, array $eventos, array $resposta): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        usort($eventos, static fn($a, $b) => strcmp((string) $b['data_evento'], (string) $a['data_evento']));
        $ultimo = $eventos[0];
        $class  = Situacao::classificarEvento($ultimo['codigo'], $ultimo['tipo'], $ultimo['descricao']);

        $upd = [
            'situacao'                => $class['situacao'],
            'ultimo_evento_codigo'    => $ultimo['codigo'],
            'ultimo_evento_tipo'      => $ultimo['tipo'],
            'ultimo_evento_descricao' => $ultimo['descricao'],
            'ultimo_evento_local'     => mb_substr(self::localDoEvento($ultimo), 0, 255),
            'ultimo_evento_data'      => $ultimo['data_evento'],
            'ultima_consulta'         => self::agora(),
            'erro_consulta'           => null,
            'prazo_retirada'          => null,
        ];

        if ($class['situacao'] === 'aguardando_retirada' && $ultimo['data_evento']) {
            $upd['prazo_retirada'] = date('Y-m-d', strtotime($ultimo['data_evento'] . ' +' . Situacao::PRAZO_RETIRADA_DIAS . ' days'));
        }
        $previsto = self::data((string) ($resposta['dtPrevista'] ?? ''));
        if ($previsto !== null) {
            $upd['prazo_previsto'] = substr($previsto, 0, 10);
        }
        if (empty($obj['servico']) && !empty($resposta['tipoPostal']['descricao'])) {
            $upd['servico'] = mb_substr((string) $resposta['tipoPostal']['descricao'], 0, 100);
        }
        if (empty($obj['data_postagem'])) {
            foreach (array_reverse($eventos) as $ev) {
                if (strtoupper($ev['codigo']) === 'PO' && $ev['data_evento']) {
                    $upd['data_postagem'] = substr($ev['data_evento'], 0, 10);
                    break;
                }
            }
        }

        $DB->update(Objeto::getTable(), $upd, ['id' => (int) $obj['id']]);

        return ['situacao' => $class['situacao'], 'rotulo' => $class['rotulo']];
    }

    private function gravarErro(int $objetoId, string $msg): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(Objeto::getTable(), [
            'erro_consulta'   => mb_substr($msg, 0, 255),
            'ultima_consulta' => self::agora(),
        ], ['id' => $objetoId]);
    }

    private static function nomeUnidade(array $u): ?string
    {
        $nome = trim((string) ($u['tipo'] ?? $u['nome'] ?? ''));

        return $nome !== '' ? mb_substr($nome, 0, 255) : null;
    }

    private static function campoEndereco(array $u, string $campo, int $max = 255): ?string
    {
        $v = trim((string) ($u['endereco'][$campo] ?? $u[$campo] ?? ''));

        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    private static function juntar(string $cidade, string $uf): string
    {
        return trim($cidade . ($uf !== '' ? ' - ' . $uf : ''));
    }

    /** "2026-09-30T10:12:00" => "2026-09-30 10:12:00" (horário de Brasília, como a API devolve). */
    public static function data(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime(substr(str_replace('T', ' ', $v), 0, 19));

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private static function agora(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }
}
