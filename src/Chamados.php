<?php

/**
 * Postal+ — integração com chamados do GLPI (Bloco 8).
 *
 * registrar(): acompanhamento público no chamado vinculado ao objeto; se não há chamado e o objeto
 * está com "Abrir chamado automaticamente", abre um chamado com categoria/grupo/prioridade da tela
 * Regras e vincula ao objeto (os alertas seguintes viram acompanhamentos nele).
 * solucionar(): regra "Entrega confirmada" — soluciona o chamado vinculado.
 * Roda também sem sessão (ação automática): entidade e requerente vêm do objeto.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use ITILFollowup;
use ITILSolution;
use Ticket;

class Chamados
{
    /**
     * @param array<string,mixed> $cfg configuração crua
     * @param array<string,mixed> $r   linha do objeto
     * @return array{ok:bool, tickets_id:int, mensagem:string}
     */
    public static function registrar(array $cfg, array $r, string $titulo, string $mensagem, string $nivel): array
    {
        $ticket = self::vinculado($r);
        if ($ticket !== null) {
            return self::acompanhar($ticket, "Postal+ · $titulo\n$mensagem");
        }
        if ((int) ($r['abrir_chamado'] ?? 0) !== 1) {
            return ['ok' => false, 'tickets_id' => 0, 'mensagem' => 'sem chamado vinculado e "Abrir chamado automaticamente" desmarcado no objeto'];
        }

        return self::abrir($cfg, $r, $titulo, $mensagem, $nivel);
    }

    /**
     * @param array<string,mixed> $r
     * @return array{ok:bool, tickets_id:int, mensagem:string}
     */
    public static function solucionar(array $r, string $mensagem): array
    {
        $ticket = self::vinculado($r);
        if ($ticket === null) {
            return ['ok' => false, 'tickets_id' => 0, 'mensagem' => 'sem chamado vinculado para solucionar'];
        }
        $st = (int) $ticket->fields['status'];
        if (in_array($st, array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()), true)) {
            return ['ok' => true, 'tickets_id' => (int) $ticket->getID(), 'mensagem' => 'chamado #' . $ticket->getID() . ' já estava solucionado/fechado'];
        }

        $sol = new ITILSolution();
        $id  = $sol->add([
            'itemtype' => Ticket::class,
            'items_id' => (int) $ticket->getID(),
            'content'  => self::html("Postal+ · Entrega confirmada\n$mensagem"),
        ]);
        if (!$id) {
            return ['ok' => false, 'tickets_id' => (int) $ticket->getID(), 'mensagem' => 'não foi possível solucionar o chamado #' . $ticket->getID()];
        }
        Configuracao::log("chamado #{$ticket->getID()} solucionado (objeto {$r['codigo']})");

        return ['ok' => true, 'tickets_id' => (int) $ticket->getID(), 'mensagem' => 'chamado #' . $ticket->getID() . ' solucionado'];
    }

    /**
     * Acompanhamento público num chamado (usado também pela reclamação registrada no Detalhe).
     *
     * @return array{ok:bool, tickets_id:int, mensagem:string}
     */
    public static function acompanhar(Ticket $ticket, string $texto): array
    {
        $f  = new ITILFollowup();
        $id = $f->add([
            'itemtype'   => Ticket::class,
            'items_id'   => (int) $ticket->getID(),
            'content'    => self::html($texto),
            'is_private' => 0,
        ]);
        if (!$id) {
            return ['ok' => false, 'tickets_id' => (int) $ticket->getID(), 'mensagem' => 'não foi possível registrar acompanhamento no chamado #' . $ticket->getID()];
        }

        return ['ok' => true, 'tickets_id' => (int) $ticket->getID(), 'mensagem' => 'acompanhamento registrado no chamado #' . $ticket->getID()];
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $r
     * @return array{ok:bool, tickets_id:int, mensagem:string}
     */
    public static function abrir(array $cfg, array $r, string $titulo, string $mensagem, string $nivel): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $input = [
            'name'                => mb_substr("Postal+ · $titulo · {$r['codigo']}", 0, 255),
            'content'             => self::html(self::resumoObjeto($r) . "\n\n$mensagem"),
            'entities_id'         => (int) $r['entities_id'],
            'status'              => Ticket::INCOMING,
            'priority'            => max(1, min(6, (int) ($cfg['chamado_prioridade'] ?? 3))),
            'type'                => Ticket::INCIDENT_TYPE,
            'itilcategories_id'   => (int) ($cfg['chamado_itilcategories_id'] ?? 0),
            '_users_id_requester' => (int) ($r['users_id'] ?? 0),
        ];
        if ((int) ($cfg['chamado_groups_id'] ?? 0) > 0) {
            $input['_groups_id_assign'] = (int) $cfg['chamado_groups_id'];
        }
        if ((int) ($r['groups_id'] ?? 0) > 0) {
            $input['_groups_id_observer'] = (int) $r['groups_id'];
        }

        $t  = new Ticket();
        $id = $t->add($input);
        if (!$id) {
            return ['ok' => false, 'tickets_id' => 0, 'mensagem' => 'o GLPI não abriu o chamado (ver php-errors.log)'];
        }
        $DB->update(Objeto::getTable(), ['tickets_id' => (int) $id], ['id' => (int) $r['id']]);
        Configuracao::log("chamado #$id aberto por alerta (objeto {$r['codigo']})");

        return ['ok' => true, 'tickets_id' => (int) $id, 'mensagem' => "chamado #$id aberto"];
    }

    /** Chamado vinculado existente e fora da lixeira, ou null. */
    public static function vinculado(array $r): ?Ticket
    {
        $tid = (int) ($r['tickets_id'] ?? 0);
        if ($tid <= 0) {
            return null;
        }
        $t = new Ticket();
        if (!$t->getFromDB($tid) || $t->isDeleted()) {
            return null;
        }

        return $t;
    }

    /** @param array<string,mixed> $r */
    public static function resumoObjeto(array $r): string
    {
        $linhas = ["Objeto {$r['codigo']}" . (!empty($r['servico']) ? " ({$r['servico']})" : '')];
        if (!empty($r['destinatario_nome'])) {
            $linhas[] = 'Destinatário: ' . $r['destinatario_nome'] . (!empty($r['destinatario_contato']) ? ' · ' . $r['destinatario_contato'] : '') . (!empty($r['destinatario_cidade']) ? ' · ' . $r['destinatario_cidade'] : '');
        }
        if (!empty($r['ultimo_evento_descricao'])) {
            $linhas[] = 'Último evento: ' . $r['ultimo_evento_descricao'] . (!empty($r['ultimo_evento_local']) ? ' · ' . $r['ultimo_evento_local'] : '')
                . (!empty($r['ultimo_evento_data']) ? ' · ' . date('d/m/Y H:i', strtotime((string) $r['ultimo_evento_data'])) : '');
        }
        $linhas[] = 'Detalhe no Postal+: ' . Notificacao::urlObjeto((string) $r['codigo']);

        return implode("\n", $linhas);
    }

    /** Texto simples → HTML (conteúdo de chamado é rich text). */
    private static function html(string $texto): string
    {
        return '<p>' . nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
}
