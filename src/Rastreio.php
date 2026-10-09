<?php

/**
 * Postal+ — consulta objetos na API Rastro e grava o resultado (Bloco 3).
 *
 * Para cada objeto: pede os eventos, grava só os novos em glpi_plugin_postalplus_eventos (hash único
 * por objeto), recalcula situação / último evento / prazos no objeto e registra a execução em
 * glpi_plugin_postalplus_consultas. Usado por "Consultar agora" (manual), pelo cadastro e pela ação
 * automática (Monitor, origem cron).
 *
 * Bloco 4: toda consulta agenda proxima_consulta conforme a frequência da situação (freq_* da
 * Configuração); entregue ou devolvido ao remetente encerra o acompanhamento (is_active = 0, sem
 * próxima). Falha de credencial/token/rede reagenda o lote para RETENTATIVA_MINUTOS.
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

    /** Depois de falha de credencial/token/rede, tentar de novo em (minutos). */
    public const RETENTATIVA_MINUTOS = 30;

    /** Situação => chave de frequência (minutos) na configuração. */
    public const FREQ_POR_SITUACAO = [
        'saiu_entrega'        => 'freq_saiu_entrega',
        'aguardando_retirada' => 'freq_aguardando_retirada',
        'problema'            => 'freq_problema',
        'em_transito'         => 'freq_transito',
        'nao_consultado'      => 'freq_transito',
        'atrasado'            => 'freq_transito',
        'sem_movimentacao'    => 'freq_transito',
    ];

    /** @var array<string,mixed>|null */
    private ?array $cfg;

    public function __construct(private Cliente $cliente, ?array $cfg = null)
    {
        $this->cfg = $cfg;
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
        $avaliar = []; // id => hashes novos (motor de regras)

        foreach ($objetos as $obj) {
            if ($fatal !== null) {
                $itens[] = ['codigo' => (string) $obj['codigo'], 'ok' => false, 'novos' => 0, 'erro' => $fatal];
                $erros++;
                $this->adiar((int) $obj['id'], self::RETENTATIVA_MINUTOS);
                continue;
            }
            try {
                $item = $this->consultarUm($obj);
                $avaliar[(int) $obj['id']] = $item['novos_hashes'] ?? [];
                unset($item['novos_hashes']);
            } catch (CwsErro $e) {
                $item = ['codigo' => (string) $obj['codigo'], 'ok' => false, 'novos' => 0, 'erro' => $e->getMessage()];
                // Falha de credencial/token/rede vale para todos: não insiste nos demais.
                if (in_array($e->tipo, ['credenciais', 'token', 'rede'], true) || in_array($e->status, [401, 403], true)) {
                    $fatal = $e->getMessage();
                }
                $this->gravarErro($obj, $e->getMessage(), $fatal !== null ? self::RETENTATIVA_MINUTOS : null);
                if ($fatal === null) {
                    $avaliar[(int) $obj['id']] = [];
                }
            }
            $itens[] = $item;
            $novos  += $item['novos'];
            $erros  += $item['ok'] ? 0 : 1;
        }

        // Motor de regras (Bloco 7): avalia os objetos consultados com a linha já atualizada.
        $alertas = 0;
        if ($avaliar !== []) {
            try {
                $alertas = (new Motor($this->config(), self::agora()))->avaliarLote($avaliar);
            } catch (\Throwable $e) {
                Configuracao::log('motor: exceção ' . $e::class . ' ' . $e->getMessage());
            }
        }

        $n         = count($objetos);
        $resultado = match (true) {
            $n === 0     => 'Nenhum objeto para consultar',
            $fatal !== null => mb_substr('Falhou: ' . $fatal, 0, 255),
            $erros > 0   => "Concluída com $erros erro(s)" . ($alertas > 0 ? " · $alertas alerta(s)" : ''),
            default      => 'Concluída' . ($alertas > 0 ? " · $alertas alerta(s)" : ''),
        };

        $DB->insert('glpi_plugin_postalplus_consultas', [
            'origem'        => mb_substr($origem, 0, 20),
            'date_start'    => $inicio,
            'date_end'      => self::agora(),
            'objetos'       => $n,
            'novos_eventos' => $novos,
            'alertas'       => $alertas,
            'erros'         => $erros,
            'resultado'     => $resultado,
            'detalhe'       => json_encode(array_values(array_filter($itens, static fn($i) => !$i['ok'])), JSON_UNESCAPED_UNICODE),
        ]);
        Configuracao::log("consulta $origem: $n objeto(s), $novos evento(s) novo(s), $alertas alerta(s), $erros erro(s)" . ($fatal !== null ? ' [interrompida]' : ''));

        return [
            'ok'           => $n > 0 && $erros === 0,
            'interrompida' => $fatal !== null,
            'objetos'      => $n,
            'novos'    => $novos,
            'alertas'  => $alertas,
            'erros'    => $erros,
            'mensagem' => self::resumo($n, $novos, $erros, $fatal, $alertas),
            'itens'    => $itens,
        ];
    }

    public static function resumo(int $n, int $novos, int $erros, ?string $fatal, int $alertas = 0): string
    {
        if ($n === 0) {
            return 'Nenhum objeto em acompanhamento para consultar.';
        }
        if ($fatal !== null) {
            return "Consulta interrompida: $fatal";
        }
        $txt = $n === 1 ? '1 objeto consultado' : "$n objetos consultados";
        $txt .= $novos === 1 ? ', 1 evento novo' : ", $novos eventos novos";
        if ($alertas > 0) {
            $txt .= $alertas === 1 ? ', 1 alerta' : ", $alertas alertas";
        }
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
            $this->gravarErro($obj, $msg);
            return ['codigo' => $codigo, 'ok' => false, 'novos' => 0, 'erro' => $msg];
        }

        $hashes = $this->gravarEventos((int) $obj['id'], $eventos);
        $class  = $this->atualizarObjeto($obj, $eventos, $resposta);

        return ['codigo' => $codigo, 'ok' => true, 'novos' => count($hashes), 'erro' => null, 'novos_hashes' => $hashes] + $class;
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
     * @return list<string> hashes dos eventos gravados agora
     */
    private function gravarEventos(int $objetoId, array $eventos): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $existentes = [];
        foreach ($DB->request(['SELECT' => ['hash'], 'FROM' => 'glpi_plugin_postalplus_eventos', 'WHERE' => ['plugin_postalplus_objetos_id' => $objetoId]]) as $r) {
            $existentes[$r['hash']] = true;
        }

        $novos = [];
        foreach ($eventos as $ev) {
            if (isset($existentes[$ev['hash']])) {
                continue;
            }
            $DB->insert('glpi_plugin_postalplus_eventos', $ev + [
                'plugin_postalplus_objetos_id' => $objetoId,
                'date_creation'                => self::agora(),
            ]);
            $existentes[$ev['hash']] = true;
            $novos[] = $ev['hash'];
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

        if (Situacao::encerraAcompanhamento($class)) {
            $upd['is_active']        = 0;
            $upd['proxima_consulta'] = null;
        } else {
            $upd['proxima_consulta'] = $this->proximaConsulta($class['situacao']);
        }

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

    /**
     * @param array<string,mixed> $obj
     * @param int|null $minutos reagendar em N minutos (null = frequência da situação atual)
     */
    private function gravarErro(array $obj, string $msg, ?int $minutos = null): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(Objeto::getTable(), [
            'erro_consulta'    => mb_substr($msg, 0, 255),
            'ultima_consulta'  => self::agora(),
            'proxima_consulta' => $minutos !== null
                ? self::somarMinutos(self::agora(), $minutos)
                : $this->proximaConsulta((string) ($obj['situacao'] ?? 'nao_consultado')),
        ], ['id' => (int) $obj['id']]);
    }

    /** Objeto não consultado porque o lote foi interrompido: só empurra a próxima consulta. */
    private function adiar(int $objetoId, int $minutos): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(Objeto::getTable(), ['proxima_consulta' => self::somarMinutos(self::agora(), $minutos)], ['id' => $objetoId]);
    }

    /** Agora + frequência configurada para a situação. */
    public function proximaConsulta(string $situacao, ?string $base = null): string
    {
        return self::somarMinutos($base ?? self::agora(), self::minutosPara($situacao, $this->config()));
    }

    /**
     * Frequência (minutos) da situação, com piso de 5 minutos.
     *
     * @param array<string,mixed> $cfg
     */
    public static function minutosPara(string $situacao, array $cfg): int
    {
        $chave = self::FREQ_POR_SITUACAO[$situacao] ?? 'freq_transito';

        return max(5, (int) ($cfg[$chave] ?? Install::configPadrao()[$chave]));
    }

    /**
     * Recalcula proxima_consulta de todos os objetos em acompanhamento (frequência mudou na Configuração):
     * base = última consulta (ou agora, se nunca consultado), nunca no passado além de agora.
     *
     * @param array<string,mixed> $cfg
     */
    public static function reagendar(array $cfg): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $agora = self::agora();
        $n     = 0;
        foreach ($DB->request([
            'SELECT' => ['id', 'situacao', 'ultima_consulta'],
            'FROM'   => Objeto::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'is_active' => 1, 'NOT' => ['situacao' => Situacao::FINAIS]],
        ]) as $r) {
            $base = !empty($r['ultima_consulta']) ? (string) $r['ultima_consulta'] : $agora;
            $DB->update(Objeto::getTable(), [
                'proxima_consulta' => self::somarMinutos($base, self::minutosPara((string) $r['situacao'], $cfg)),
            ], ['id' => (int) $r['id']]);
            $n++;
        }

        return $n;
    }

    public static function somarMinutos(string $data, int $minutos): string
    {
        return date('Y-m-d H:i:s', strtotime($data) + $minutos * 60);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return $this->cfg ??= Configuracao::lerCru();
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
