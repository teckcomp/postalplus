<?php

/**
 * Postal+ — leitura e gravação das 5 regras fixas de alerta (glpi_plugin_postalplus_regras).
 *
 * Só altera linhas existentes (criadas pelo Install). Nunca cria nem apaga regra.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

class Regras
{
    public const TABELA = 'glpi_plugin_postalplus_regras';

    /** Eventos críticos selecionáveis (slug => rótulo). Mapeamento SRO -> slug no Bloco 3/7. */
    public const EVENTOS_CRITICOS = [
        'carteiro_nao_atendido' => 'Carteiro não atendido',
        'endereco_incorreto'    => 'Endereço incorreto ou insuficiente',
        'destinatario_mudou'    => 'Destinatário mudou-se',
        'recusado'              => 'Recusado pelo destinatário',
        'devolucao'             => 'Objeto em devolução ao remetente',
        'extraviado'            => 'Objeto extraviado',
        'avariado'              => 'Objeto avariado',
    ];

    public const CANAIS = ['canal_tela', 'canal_email', 'canal_whatsapp', 'canal_chamado'];

    /**
     * Textos e limites de cada regra, na ordem da tela.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function metadados(): array
    {
        return [
            'aguardando_retirada' => [
                'titulo' => 'Aguardando retirada', 'tag' => 'Evita devolução', 'cor' => 'amarela',
                'desc'   => 'Avisa quando o objeto chega à agência e lembra antes de o prazo de retirada acabar.',
                'param'  => ['antes' => 'Lembrar faltando', 'depois' => 'dia(s) para o fim do prazo', 'min' => 1, 'max' => 7],
                'chamado' => 'Abrir chamado',
            ],
            'sem_movimentacao' => [
                'titulo' => 'Sem movimentação', 'tag' => 'Possível extravio', 'cor' => 'roxa',
                'desc'   => 'Nenhum evento novo no rastreio por tempo demais: forte indício de extravio ou perda de rastreio.',
                'param'  => ['antes' => 'Disparar após', 'depois' => 'dia(s) úteis sem novo evento', 'min' => 1, 'max' => 30],
                'chamado' => 'Abrir chamado',
            ],
            'atraso' => [
                'titulo' => 'Atraso na entrega', 'tag' => 'Prazo', 'cor' => 'laranja',
                'desc'   => 'Prazo previsto do serviço ultrapassado e o objeto ainda não foi entregue.',
                'param'  => ['antes' => 'Tolerância de', 'depois' => 'dia(s) após o prazo previsto', 'min' => 0, 'max' => 30],
                'chamado' => 'Abrir chamado',
            ],
            'eventos_criticos' => [
                'titulo' => 'Eventos críticos', 'tag' => 'Imediato', 'cor' => 'vermelha',
                'desc'   => 'Dispara no mesmo ciclo em que qualquer um destes eventos aparece no rastreio.',
                'param'  => null,
                'chamado' => 'Abrir chamado',
            ],
            'entrega_confirmada' => [
                'titulo' => 'Entrega confirmada', 'tag' => 'Encerramento', 'cor' => 'verde',
                'desc'   => 'Encerra o acompanhamento do objeto e, se houver, soluciona o chamado vinculado.',
                'param'  => null,
                'chamado' => 'Solucionar chamado',
            ],
        ];
    }

    /**
     * Regras gravadas, na ordem dos metadados, já com os textos.
     *
     * @return list<array<string,mixed>>
     */
    public static function listar(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $gravadas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $r) {
            $gravadas[$r['chave']] = $r;
        }

        $lista = [];
        foreach (self::metadados() as $chave => $meta) {
            if (!isset($gravadas[$chave])) {
                continue;
            }
            $r       = $gravadas[$chave];
            $eventos = json_decode((string) ($r['eventos'] ?? ''), true);
            $lista[] = $meta + [
                'chave'          => $chave,
                'is_active'      => (int) $r['is_active'] === 1,
                'parametro'      => $r['parametro'] === null ? null : (int) $r['parametro'],
                'canal_tela'     => (int) $r['canal_tela'] === 1,
                'canal_email'    => (int) $r['canal_email'] === 1,
                'canal_whatsapp' => (int) $r['canal_whatsapp'] === 1,
                'canal_chamado'  => (int) $r['canal_chamado'] === 1,
                'eventos'        => is_array($eventos) ? array_values($eventos) : [],
            ];
        }

        return $lista;
    }

    /**
     * Valida o formulário da tela "Regras de alerta".
     * Formato: regra[<chave>][is_active|parametro|canal_*|eventos[]].
     *
     * @return list<string> erros (vazio = pode gravar)
     */
    public static function validar(array $post): array
    {
        return self::montar($post)['erros'];
    }

    /** Grava o formulário já validado (não grava nada se houver erro). */
    public static function salvar(array $post, ?string $agora = null): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $m = self::montar($post, $agora);
        if ($m['erros'] !== []) {
            return false;
        }
        foreach ($m['updates'] as $chave => $upd) {
            $DB->update(self::TABELA, $upd, ['chave' => $chave]);
        }

        return true;
    }

    /**
     * @return array{erros:list<string>, updates:array<string,array<string,mixed>>}
     */
    private static function montar(array $post, ?string $agora = null): array
    {
        $entrada = (array) ($post['regra'] ?? []);
        $erros   = [];
        $updates = [];

        foreach (self::metadados() as $chave => $meta) {
            $r   = (array) ($entrada[$chave] ?? []);
            $upd = ['is_active' => empty($r['is_active']) ? 0 : 1];

            foreach (self::CANAIS as $canal) {
                $upd[$canal] = empty($r[$canal]) ? 0 : 1;
            }

            if ($meta['param'] !== null) {
                $v = filter_var($r['parametro'] ?? null, FILTER_VALIDATE_INT);
                if ($v === false || $v < $meta['param']['min'] || $v > $meta['param']['max']) {
                    $erros[] = sprintf('%s: informe um número de %d a %d.', $meta['titulo'], $meta['param']['min'], $meta['param']['max']);
                    continue;
                }
                $upd['parametro'] = $v;
            }

            if ($chave === 'eventos_criticos') {
                $sel = array_values(array_intersect(array_keys(self::EVENTOS_CRITICOS), (array) ($r['eventos'] ?? [])));
                if ($upd['is_active'] === 1 && $sel === []) {
                    $erros[] = 'Eventos críticos: marque ao menos um evento ou desative a regra.';
                    continue;
                }
                $upd['eventos'] = json_encode($sel);
            }

            if ($upd['is_active'] === 1 && ($upd['canal_tela'] + $upd['canal_email'] + $upd['canal_whatsapp'] + $upd['canal_chamado']) === 0) {
                $erros[] = $meta['titulo'] . ': marque ao menos um canal ou desative a regra.';
                continue;
            }

            $upd['date_mod'] = $agora ?? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
            $updates[$chave] = $upd;
        }

        return ['erros' => $erros, 'updates' => $updates];
    }
}
