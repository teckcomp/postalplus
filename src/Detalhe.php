<?php

/**
 * Postal+ — dados das abas do Detalhe do objeto (Bloco 6).
 *
 * Alertas: tabela glpi_plugin_postalplus_alertas (gravada pelo motor de regras do Bloco 7).
 * Chamados: chamado vinculado + chamados abertos por alerta (Bloco 8), só com título/status para
 * quem pode ver o chamado. Documentos: os do chamado vinculado (no chamado, acompanhamentos,
 * tarefas, solução, validação), com a mesma regra de visibilidade do GLPI
 * (CommonITILObject::getAssociatedDocumentsCriteria) — imagens embutidas no texto ficam de fora.
 * Histórico: montado do que já está gravado (cadastro, consultas que trouxeram eventos, alertas,
 * última consulta, encerramento) — não há tabela própria.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use CommonITILObject;
use Document;
use Document_Item;
use Dropdown;
use Ticket;

class Detalhe
{
    /** Rótulo de cada canal gravado em alertas.canais (lista separada por vírgula). */
    public const CANAIS = [
        'tela'     => 'Tela',
        'email'    => 'E-mail equipe',
        'whatsapp' => 'WhatsApp destinatário',
        'chamado'  => 'Chamado',
    ];

    /** Onde o documento está anexado dentro do chamado. */
    public const ONDE_DOCUMENTO = [
        'Ticket'           => 'Chamado',
        'ITILFollowup'     => 'Acompanhamento',
        'TicketTask'       => 'Tarefa',
        'ITILSolution'     => 'Solução',
        'TicketValidation' => 'Validação',
    ];

    /** Máximo de itens por aba (o objeto é de um envio: listas curtas). */
    public const LIMITE = 200;

    public static function canaisRotulo(?string $canais): string
    {
        $rotulos = [];
        foreach (explode(',', (string) $canais) as $c) {
            $c = trim($c);
            if ($c !== '') {
                $rotulos[] = self::CANAIS[$c] ?? $c;
            }
        }

        return implode(' · ', $rotulos);
    }

    /** "Hoje 08:00", "Ontem 14:40" ou "30/09/2026 10:20". */
    public static function quando(?string $data, ?string $agora = null): string
    {
        if (empty($data)) {
            return '';
        }
        $ts   = strtotime($data);
        $hoje = date('Y-m-d', strtotime($agora ?? ($_SESSION['glpi_currenttime'] ?? 'now')));
        $dia  = date('Y-m-d', $ts);
        if ($dia === $hoje) {
            return 'Hoje ' . date('H:i', $ts);
        }
        if ($dia === date('Y-m-d', strtotime("$hoje -1 day"))) {
            return 'Ontem ' . date('H:i', $ts);
        }

        return date('d/m/Y H:i', $ts);
    }

    /**
     * Alertas do objeto, mais recentes primeiro (linhas cruas da tabela).
     *
     * @return list<array<string,mixed>>
     */
    public static function alertasCrus(int $id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_postalplus_alertas',
            'WHERE' => ['plugin_postalplus_objetos_id' => $id],
            'ORDER' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => self::LIMITE,
        ]), false);
    }

    /**
     * @param list<array<string,mixed>> $crus
     * @return list<array<string,mixed>>
     */
    public static function alertasParaTela(array $crus): array
    {
        $lista = [];
        foreach ($crus as $a) {
            $nivel   = in_array($a['nivel'], ['critico', 'atencao'], true) ? (string) $a['nivel'] : 'info';
            $lista[] = [
                'quando'   => self::quando($a['date_creation'] ?? null),
                'titulo'   => (string) ($a['titulo'] ?: ($a['regra'] ?? 'Alerta')),
                'mensagem' => (string) ($a['mensagem'] ?? ''),
                'nivel'    => $nivel,
                'canais'   => self::canaisRotulo($a['canais'] ?? ''),
                'chamado'  => (int) ($a['tickets_id'] ?? 0),
            ];
        }

        return $lista;
    }

    /**
     * Chamado vinculado + chamados registrados pelos alertas, sem repetir.
     * Quem não pode ver o chamado vê só o número.
     *
     * @param list<array<string,mixed>> $alertas linhas cruas
     * @return list<array<string,mixed>>
     */
    public static function chamados(int $vinculado, array $alertas): array
    {
        $ids = [];
        if ($vinculado > 0) {
            $ids[$vinculado] = 'vinculado';
        }
        foreach (array_reverse($alertas) as $a) {
            $t = (int) ($a['tickets_id'] ?? 0);
            if ($t > 0 && !isset($ids[$t])) {
                $ids[$t] = 'alerta';
            }
        }

        $lista = [];
        foreach ($ids as $tid => $origem) {
            $item = [
                'id'          => $tid,
                'origem'      => $origem,
                'existe'      => false,
                'visivel'     => false,
                'titulo'      => '',
                'status'      => '',
                'status_classe' => '',
                'aberto'      => '',
                'atualizado'  => '',
                'fechado'     => false,
            ];
            $t = new Ticket();
            if ($t->getFromDB($tid) && !$t->isDeleted()) {
                $item['existe'] = true;
                if ($t->canViewItem()) {
                    $st                    = (int) $t->fields['status'];
                    $item['visivel']       = true;
                    $item['titulo']        = (string) $t->fields['name'];
                    $item['status']        = (string) Ticket::getStatus($st);
                    $item['status_classe'] = (string) Ticket::getStatusClass($st);
                    $item['aberto']        = $t->fields['date'] ? date('d/m/Y H:i', strtotime((string) $t->fields['date'])) : '';
                    $item['atualizado']    = $t->fields['date_mod'] ? date('d/m/Y H:i', strtotime((string) $t->fields['date_mod'])) : '';
                    $item['fechado']       = in_array($st, array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()), true);
                }
            }
            $lista[] = $item;
        }

        return $lista;
    }

    /**
     * Documentos do chamado vinculado, como o GLPI mostra na linha do tempo do chamado.
     *
     * @return array{chamado:int, situacao:string, itens:list<array<string,mixed>>}
     *         situacao: sem_chamado | sem_acesso | inexistente | ok
     */
    public static function documentos(int $ticketId, string $webRoot): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $saida = ['chamado' => $ticketId, 'situacao' => 'sem_chamado', 'itens' => []];
        if ($ticketId <= 0) {
            return $saida;
        }
        $t = new Ticket();
        if (!$t->getFromDB($ticketId) || $t->isDeleted()) {
            $saida['situacao'] = 'inexistente';
            return $saida;
        }
        if (!$t->canViewItem()) {
            $saida['situacao'] = 'sem_acesso';
            return $saida;
        }
        $saida['situacao'] = 'ok';

        $di = Document_Item::getTable();
        $dt = Document::getTable();
        foreach ($DB->request([
            'SELECT' => [
                "$dt.id", "$dt.name", "$dt.filename", "$dt.mime",
                "$di.itemtype", "$di.date", "$di.users_id", "$di.date_creation AS vinculo",
            ],
            'FROM'   => $di,
            'INNER JOIN' => [$dt => ['ON' => [$di => 'documents_id', $dt => 'id']]],
            'WHERE'  => [
                $t->getAssociatedDocumentsCriteria(),
                "$dt.is_deleted" => 0,
                // Imagem colada no texto (NO_TIMELINE) não é anexo do envio.
                ['OR' => ["$di.timeline_position" => null, ['NOT' => ["$di.timeline_position" => CommonITILObject::NO_TIMELINE]]]],
            ],
            'ORDER'  => ["$di.date DESC", "$dt.id DESC"],
            'LIMIT'  => self::LIMITE,
        ]) as $d) {
            $id = (int) $d['id'];
            if (isset($saida['itens'][$id])) {
                continue; // mesmo arquivo ligado ao chamado e a um acompanhamento
            }
            $quando = $d['date'] ?: $d['vinculo'];
            $saida['itens'][$id] = [
                'id'      => $id,
                'arquivo' => (string) ($d['filename'] ?: $d['name']),
                'nome'    => (string) $d['name'],
                'onde'    => self::ONDE_DOCUMENTO[$d['itemtype']] ?? (string) $d['itemtype'],
                'data'    => $quando ? date('d/m/Y H:i', strtotime((string) $quando)) : '',
                'usuario' => (int) $d['users_id'] > 0 ? (string) Dropdown::getDropdownName('glpi_users', (int) $d['users_id']) : '',
                'url'     => $webRoot . '/front/document.send.php?docid=' . $id . '&itemtype=Ticket&items_id=' . $ticketId,
            ];
        }
        $saida['itens'] = array_values($saida['itens']);

        return $saida;
    }

    /**
     * Histórico do acompanhamento, mais recente primeiro.
     *
     * Consultas sem novidade não deixam rastro por objeto: aparece só a última (com o erro, se houve).
     *
     * @param array<string,mixed>        $r       linha do objeto
     * @param list<array<string,mixed>>  $alertas linhas cruas
     * @return list<array{ts:int, quando:string, icone:string, tipo:string, titulo:string, sub:string}>
     */
    public static function historico(array $r, array $alertas): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $itens = [];
        $add   = static function (?string $data, string $tipo, string $icone, string $titulo, string $sub = '') use (&$itens): void {
            if (empty($data)) {
                return;
            }
            $itens[] = ['ts' => strtotime($data), 'quando' => date('d/m/Y H:i', strtotime($data)), 'icone' => $icone, 'tipo' => $tipo, 'titulo' => $titulo, 'sub' => $sub];
        };

        $quem = (int) $r['users_id'] > 0 ? (string) Dropdown::getDropdownName('glpi_users', (int) $r['users_id']) : '';
        $add(
            $r['date_creation'] ?? null,
            'cadastro',
            'ti ti-package',
            'Objeto cadastrado no Postal+',
            trim(Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']) . ($quem !== '' ? " · responsável $quem" : ''))
        );

        // Consultas que gravaram eventos: agrupadas pelo momento em que o plugin os recebeu.
        $grupos = [];
        foreach ($DB->request([
            'SELECT' => ['descricao', 'data_evento', 'date_creation'],
            'FROM'   => 'glpi_plugin_postalplus_eventos',
            'WHERE'  => ['plugin_postalplus_objetos_id' => (int) $r['id']],
            'ORDER'  => ['date_creation ASC', 'data_evento ASC', 'id ASC'],
        ]) as $e) {
            $chave = substr((string) $e['date_creation'], 0, 16); // por minuto
            $grupos[$chave] ??= ['data' => (string) $e['date_creation'], 'n' => 0, 'ultimo' => ''];
            $grupos[$chave]['n']++;
            $grupos[$chave]['ultimo'] = (string) $e['descricao'];
        }
        $ultimaComEvento = 0;
        foreach ($grupos as $g) {
            $add(
                $g['data'],
                'consulta',
                'ti ti-refresh',
                'Consulta à API: ' . ($g['n'] === 1 ? '1 evento novo' : "{$g['n']} eventos novos"),
                'Mais recente: ' . $g['ultimo']
            );
            $ultimaComEvento = max($ultimaComEvento, strtotime($g['data']));
        }

        foreach ($alertas as $a) {
            $canais = self::canaisRotulo($a['canais'] ?? '');
            $sub    = $canais;
            if ((int) ($a['tickets_id'] ?? 0) > 0) {
                $sub .= ($sub !== '' ? ' · ' : '') . 'chamado #' . (int) $a['tickets_id'];
            }
            $add($a['date_creation'] ?? null, 'alerta', 'ti ti-bell', 'Alerta: ' . ($a['titulo'] ?: $a['regra']), $sub);
        }

        // Última consulta, quando não é a mesma que trouxe eventos (ou quando falhou).
        $ultima = $r['ultima_consulta'] ?? null;
        $erro   = (string) ($r['erro_consulta'] ?? '');
        if (!empty($ultima) && ($erro !== '' || strtotime((string) $ultima) - $ultimaComEvento >= 60)) {
            $add(
                (string) $ultima,
                $erro !== '' ? 'erro' : 'consulta',
                $erro !== '' ? 'ti ti-alert-triangle' : 'ti ti-refresh',
                $erro !== '' ? 'Consulta à API sem sucesso' : 'Consulta à API: nenhum evento novo',
                $erro
            );
        }

        // Encerramento: no momento em que chegou o evento final.
        if ((int) ($r['is_active'] ?? 1) === 0) {
            $quando = $ultimaComEvento > 0 ? date('Y-m-d H:i:s', $ultimaComEvento) : ($r['date_mod'] ?? null);
            $motivo = (string) $r['situacao'] === 'entregue' ? 'objeto entregue' : 'objeto devolvido ao remetente';
            $add($quando, 'encerrado', 'ti ti-flag-check', 'Acompanhamento encerrado', "Consultas automáticas paradas: $motivo");
        }

        // Mais recente primeiro; no mesmo instante, encerramento antes da consulta que o causou.
        $peso = ['encerrado' => 0, 'alerta' => 1, 'erro' => 2, 'consulta' => 3, 'cadastro' => 4];
        usort($itens, static fn($a, $b) => [$b['ts'], $peso[$a['tipo']]] <=> [$a['ts'], $peso[$b['tipo']]]);

        return $itens;
    }
}
