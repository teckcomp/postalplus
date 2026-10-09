<?php

/**
 * Postal+ — alertas em tela: pendentes por usuário e leitura (Bloco 7).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use Session;

class Alertas
{
    public const TABELA   = 'glpi_plugin_postalplus_alertas';
    public const LEITURAS = 'glpi_plugin_postalplus_alertaleituras';

    /** Alertas mais velhos que isto não aparecem mais no toast. */
    public const DIAS_TELA = 3;

    public const MAXIMO = 5;

    /** Perfis permitidos na Configuração (lista vazia = todos com direito de leitura). */
    public static function perfilPermitido(array $cfg): bool
    {
        $perfis = json_decode((string) ($cfg['alerta_perfis'] ?? '[]'), true);
        if (!is_array($perfis) || $perfis === []) {
            return true;
        }

        return in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), array_map('intval', $perfis), true);
    }

    /** @return list<array<string,mixed>> */
    public static function pendentes(array $cfg, ?int $usuario = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $usuario ??= (int) Session::getLoginUserID();
        $t  = self::TABELA;
        $o  = Objeto::getTable();
        $l  = self::LEITURAS;
        $de = date('Y-m-d H:i:s', strtotime(($_SESSION['glpi_currenttime'] ?? 'now') . ' -' . self::DIAS_TELA . ' days'));

        $where = [
            "$t.date_creation" => ['>=', $de],
            "$t.canais"        => ['LIKE', '%tela%'],
            "$o.is_deleted"    => 0,
            "$l.id"            => null,
        ];
        $ent = getEntitiesRestrictCriteria($o, '', '', true);
        if ($ent !== []) {
            $where[] = $ent;
        }

        $lista = [];
        foreach ($DB->request([
            'SELECT'     => ["$t.id", "$t.nivel", "$t.titulo", "$t.mensagem", "$t.date_creation", "$t.tickets_id", "$o.codigo"],
            'FROM'       => $t,
            'INNER JOIN' => [$o => ['ON' => [$t => 'plugin_postalplus_objetos_id', $o => 'id']]],
            'LEFT JOIN'  => [$l => ['ON' => [$l => 'plugin_postalplus_alertas_id', $t => 'id', ['AND' => ["$l.users_id" => $usuario]]]]],
            'WHERE'      => $where,
            'ORDER'      => ["$t.date_creation DESC", "$t.id DESC"],
            'LIMIT'      => self::MAXIMO,
        ]) as $a) {
            $lista[] = [
                'id'       => (int) $a['id'],
                'nivel'    => (string) $a['nivel'],
                'titulo'   => (string) $a['titulo'],
                'mensagem' => (string) $a['mensagem'],
                'codigo'   => (string) $a['codigo'],
                'chamado'  => (int) $a['tickets_id'],
                'quando'   => Detalhe::quando((string) $a['date_creation']),
            ];
        }

        return $lista;
    }

    public static function marcarLido(int $alertaId, ?int $usuario = null): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $usuario ??= (int) Session::getLoginUserID();
        if ($alertaId <= 0 || $usuario <= 0) {
            return 0;
        }
        $ja = $DB->request(['COUNT' => 'cpt', 'FROM' => self::LEITURAS, 'WHERE' => ['plugin_postalplus_alertas_id' => $alertaId, 'users_id' => $usuario]])->current();
        if ((int) ($ja['cpt'] ?? 0) > 0) {
            return 0;
        }
        $DB->insert(self::LEITURAS, [
            'plugin_postalplus_alertas_id' => $alertaId,
            'users_id'                     => $usuario,
            'date_read'                    => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        return 1;
    }

    public static function marcarTodosLidos(array $cfg, ?int $usuario = null): int
    {
        $n = 0;
        // pendentes() devolve no máximo MAXIMO por vez: repete até esvaziar (com teto).
        for ($i = 0; $i < 50; $i++) {
            $lote = self::pendentes($cfg, $usuario);
            if ($lote === []) {
                break;
            }
            foreach ($lote as $a) {
                $n += self::marcarLido((int) $a['id'], $usuario);
            }
        }

        return $n;
    }
}
