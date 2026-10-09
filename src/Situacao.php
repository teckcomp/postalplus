<?php

/**
 * Postal+ — situações do objeto e agrupamento dos cards do painel.
 *
 * Slugs gravados em glpi_plugin_postalplus_objetos.situacao. Bloco 3: classificarEvento() mapeia o
 * evento SRO (codigo/tipo/descricao) para situação + rótulo da pílula + slug de evento crítico.
 *
 * O mapeamento olha primeiro a DESCRIÇÃO (texto estável e conhecido) e só depois os códigos SRO,
 * porque a tabela completa de codigo/tipo ainda precisa ser validada na API de homologação.
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

    /** Dias corridos para retirada na agência (mockup: chegou 30/09, retirar até 07/10). A validar. */
    public const PRAZO_RETIRADA_DIAS = 7;

    /** Situações em que o acompanhamento terminou (não consultar mais). */
    public const FINAIS = ['entregue'];

    /**
     * Classifica um evento SRO.
     *
     * @return array{situacao:string, rotulo:string, critico:?string}
     */
    public static function classificarEvento(string $codigo, string $tipo, string $descricao): array
    {
        $d      = self::semAcento(mb_strtolower($descricao));
        $codigo = strtoupper(trim($codigo));
        $tipo   = trim($tipo);

        $problemas = [
            ['extraviad', 'Objeto extraviado', 'extraviado'],
            ['avariad', 'Objeto avariado', 'avariado'],
            ['carteiro nao atendido', 'Carteiro não atendido', 'carteiro_nao_atendido'],
            ['endereco incorreto', 'Endereço incorreto', 'endereco_incorreto'],
            ['endereco insuficiente', 'Endereço incorreto', 'endereco_incorreto'],
            ['mudou-se', 'Destinatário mudou-se', 'destinatario_mudou'],
            ['recusad', 'Recusado', 'recusado'],
            ['entregue ao remetente', 'Devolvido ao remetente', 'devolucao'],
            ['devolucao ao remetente', 'Em devolução', 'devolucao'],
            ['devolvido ao remetente', 'Em devolução', 'devolucao'],
            ['em devolucao', 'Em devolução', 'devolucao'],
        ];
        foreach ($problemas as [$trecho, $rotulo, $critico]) {
            if (str_contains($d, $trecho)) {
                return ['situacao' => 'problema', 'rotulo' => $rotulo, 'critico' => $critico];
            }
        }

        if (str_contains($d, 'aguardando retirada') || $codigo === 'LDI') {
            return ['situacao' => 'aguardando_retirada', 'rotulo' => 'Aguardando retirada', 'critico' => null];
        }
        if (str_contains($d, 'entregue ao destinatario') || (in_array($codigo, ['BDE', 'BDI', 'BDR'], true) && $tipo === '01')) {
            return ['situacao' => 'entregue', 'rotulo' => 'Entregue', 'critico' => null];
        }
        if (str_contains($d, 'saiu para entrega') || $codigo === 'OEC') {
            return ['situacao' => 'saiu_entrega', 'rotulo' => 'Saiu para entrega', 'critico' => null];
        }
        if ($codigo === 'PO' || str_contains($d, 'objeto postado')) {
            return ['situacao' => 'em_transito', 'rotulo' => 'Postado', 'critico' => null];
        }

        return ['situacao' => 'em_transito', 'rotulo' => 'Em trânsito', 'critico' => null];
    }

    public static function semAcento(string $s): string
    {
        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }

    /** Cor do ponto na linha do tempo do Detalhe. */
    public static function corDoEvento(string $situacao, string $rotulo): string
    {
        return match (true) {
            $situacao === 'entregue'            => 'entregue',
            $situacao === 'saiu_entrega'        => 'saiu',
            $situacao === 'aguardando_retirada' => 'retirada',
            $situacao === 'problema'            => 'problema',
            $rotulo === 'Postado'               => 'postado',
            default                             => 'transito',
        };
    }
}
