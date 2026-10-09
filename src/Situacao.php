<?php

/**
 * Postal+ — situações do objeto e agrupamento dos cards do painel.
 *
 * Slugs gravados em glpi_plugin_postalplus_objetos.situacao. O mapeamento SRO -> slug entra no Bloco 3.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

class Situacao
{
    public const ROTULOS = [
        'nao_consultado'      => 'Não consultado',
        'em_transito'         => 'Em trânsito',
        'saiu_entrega'        => 'Saiu para entrega',
        'aguardando_retirada' => 'Aguardando retirada',
        'atrasado'            => 'Atrasado',
        'sem_movimentacao'    => 'Sem movimentação',
        'problema'            => 'Problema',
        'entregue'            => 'Entregue',
    ];

    /**
     * Cards do painel: chave => [rótulo, descrição, situações que somam, classe da pílula].
     *
     * @return array<string,array{rotulo:string,desc:string,situacoes:list<string>,classe:string}>
     */
    public static function cards(): array
    {
        return [
            'em_transito'         => ['rotulo' => 'Em trânsito', 'desc' => 'inclui saiu para entrega', 'situacoes' => ['em_transito', 'saiu_entrega', 'nao_consultado'], 'classe' => 'em_transito'],
            'aguardando_retirada' => ['rotulo' => 'Aguardando retirada', 'desc' => 'na agência dos Correios', 'situacoes' => ['aguardando_retirada'], 'classe' => 'aguardando_retirada'],
            'atrasado'            => ['rotulo' => 'Atrasados', 'desc' => 'prazo do serviço excedido', 'situacoes' => ['atrasado'], 'classe' => 'atrasado'],
            'sem_movimentacao'    => ['rotulo' => 'Sem movimentação', 'desc' => '5+ dias úteis sem evento', 'situacoes' => ['sem_movimentacao'], 'classe' => 'sem_movimentacao'],
            'problema'            => ['rotulo' => 'Problemas', 'desc' => 'falha de entrega ou devolução', 'situacoes' => ['problema'], 'classe' => 'problema'],
            'entregue'            => ['rotulo' => 'Entregues', 'desc' => 'últimos 30 dias', 'situacoes' => ['entregue'], 'classe' => 'entregue'],
        ];
    }

    /** Card em que uma situação conta. */
    public static function cardDe(string $situacao): string
    {
        foreach (self::cards() as $chave => $card) {
            if (in_array($situacao, $card['situacoes'], true)) {
                return $chave;
            }
        }

        return 'em_transito';
    }

    /**
     * Totais por card.
     *
     * @param list<array{situacao:string}> $objetos
     * @return array<string,int>
     */
    public static function contar(array $objetos): array
    {
        $totais = array_fill_keys(array_keys(self::cards()), 0);
        foreach ($objetos as $o) {
            $totais[self::cardDe($o['situacao'])]++;
        }

        return $totais;
    }

    /** Código de rastreio: 2 letras + 9 dígitos + 2 letras. */
    public static function codigoValido(string $codigo): bool
    {
        return (bool) preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $codigo);
    }
}
