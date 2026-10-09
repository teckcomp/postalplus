<?php

/**
 * Postal+ — dados de DEMONSTRAÇÃO das telas do Bloco 1b (os 9 objetos do mockup).
 *
 * Temporário: o Painel deixou de usá-la no Bloco 5; o Detalhe (códigos da demo) sai no Bloco 6.
 * Nada aqui é gravado no banco.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

class Demo
{
    /** Data de referência dos dados do mockup ("hoje" da demonstração). */
    public const HOJE = '2026-10-05';

    /**
     * @return list<array<string,mixed>>
     */
    public static function objetos(): array
    {
        $o = static fn(array $a) => $a + [
            'demo' => true, 'alerta_nivel' => '', 'chamado' => 0, 'contato' => '', 'whatsapp' => '', 'email' => '', 'retirada' => '', 'eventos' => [],
            'entidade' => '', 'responsavel' => '', 'grupo' => '',
        ];

        return [
            $o([
                'codigo' => 'QP302718234BR', 'servico' => 'PAC Contrato', 'situacao' => 'aguardando_retirada', 'rotulo' => 'Aguardando retirada',
                'evento' => 'Objeto aguardando retirada no endereço indicado', 'local' => 'Agência dos Correios · LONDRINA - PR',
                'atualizado' => '30/09 10:12', 'alerta' => 'Retirar até 07/10 · faltam 2 dias', 'alerta_nivel' => 'atencao',
                'destinatario' => 'Mariana S.', 'cidade' => 'Londrina/PR', 'chamado' => 4521, 'chamado_titulo' => 'Envio equipamento cliente',
                'whatsapp' => '(43) 9••••-••21', 'retirada' => 'Agência dos Correios · Centro',
                'postagem' => '26/09/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '01/10/2026',
                'prazo_retirada' => ['restantes' => 2, 'chegou' => '30/09', 'ate' => '07/10/2026', 'decorridos' => 5, 'total' => 7],
                'eventos' => [
                    ['data' => '30/09', 'hora' => '10:12', 'titulo' => 'Objeto aguardando retirada no endereço indicado', 'local' => 'Agência dos Correios · LONDRINA - PR', 'cor' => 'retirada', 'atual' => true,
                     'nota' => 'Para retirá-lo é preciso informar o código do objeto e apresentar documento de identificação com foto.'],
                    ['data' => '29/09', 'hora' => '14:30', 'titulo' => 'A entrega não pode ser efetuada - Carteiro não atendido', 'local' => 'Unidade de Distribuição · LONDRINA - PR', 'cor' => 'problema'],
                    ['data' => '29/09', 'hora' => '08:02', 'titulo' => 'Objeto saiu para entrega ao destinatário', 'local' => 'Unidade de Distribuição · LONDRINA - PR', 'cor' => 'saiu'],
                    ['data' => '27/09', 'hora' => '22:10', 'titulo' => 'Objeto em transferência - por favor aguarde', 'local' => 'de Unidade de Tratamento, CURITIBA - PR para Unidade de Distribuição, LONDRINA - PR', 'cor' => 'transito'],
                    ['data' => '26/09', 'hora' => '16:45', 'titulo' => 'Objeto postado', 'local' => 'Agência dos Correios · CURITIBA - PR', 'cor' => 'postado'],
                ],
                'alertas' => [
                    ['quando' => 'Hoje 08:00', 'titulo' => 'Lembrete: faltam 2 dias', 'canais' => 'Tela · WhatsApp destinatário · e-mail equipe'],
                    ['quando' => '30/09 10:20', 'titulo' => 'Chegou na agência', 'canais' => 'Tela · WhatsApp destinatário'],
                    ['quando' => '29/09 14:40', 'titulo' => 'Carteiro não atendido', 'canais' => 'Tela · chamado #4521 aberto automaticamente'],
                ],
            ]),
            $o([
                'codigo' => 'OY975530218BR', 'servico' => 'SEDEX Contrato', 'situacao' => 'atrasado', 'rotulo' => 'Atrasado',
                'evento' => 'Objeto em transferência - por favor aguarde', 'local' => 'de Unid. Tratamento CURITIBA - PR para Unid. Distribuição MARINGA - PR',
                'atualizado' => '01/10 19:40', 'alerta' => 'Previsto 01/10 · 4 dias de atraso', 'alerta_nivel' => 'critico',
                'destinatario' => 'Paulo R.', 'cidade' => 'Maringá/PR', 'chamado' => 4517, 'postagem' => '29/09/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '01/10/2026',
            ]),
            $o([
                'codigo' => 'QP298811470BR', 'servico' => 'PAC Contrato', 'situacao' => 'sem_movimentacao', 'rotulo' => 'Sem movimentação',
                'evento' => 'Objeto em transferência - por favor aguarde', 'local' => 'Unidade de Tratamento · CURITIBA - PR',
                'atualizado' => '28/09 21:05', 'alerta' => '7 dias sem novo evento', 'alerta_nivel' => 'critico',
                'destinatario' => 'Loja parceira', 'cidade' => 'Cascavel/PR', 'chamado' => 4509, 'postagem' => '26/09/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '03/10/2026',
            ]),
            $o([
                'codigo' => 'QP301145902BR', 'servico' => 'PAC Contrato', 'situacao' => 'problema', 'rotulo' => 'Carteiro não atendido',
                'evento' => 'A entrega não pode ser efetuada - Carteiro não atendido', 'local' => 'Unidade de Distribuição · PONTA GROSSA - PR',
                'atualizado' => '03/10 14:22', 'alerta' => 'Aguardando nova tentativa', 'alerta_nivel' => 'atencao',
                'destinatario' => 'Ana L.', 'cidade' => 'Ponta Grossa/PR', 'chamado' => 4525, 'postagem' => '30/09/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '06/10/2026',
            ]),
            $o([
                'codigo' => 'OY968842107BR', 'servico' => 'SEDEX Contrato', 'situacao' => 'problema', 'rotulo' => 'Em devolução',
                'evento' => 'Objeto em devolução ao remetente', 'local' => 'Agência dos Correios · BLUMENAU - SC',
                'atualizado' => '02/10 09:30', 'alerta' => 'Prazo de retirada esgotado', 'alerta_nivel' => 'critico',
                'destinatario' => 'Tiago M.', 'cidade' => 'Blumenau/SC', 'chamado' => 4498, 'postagem' => '19/09/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '22/09/2026',
            ]),
            $o([
                'codigo' => 'YJ460348417BR', 'servico' => 'SEDEX Contrato', 'situacao' => 'em_transito', 'rotulo' => 'Em trânsito',
                'evento' => 'Objeto em transferência - por favor aguarde', 'local' => 'de Unid. Tratamento CURITIBA - PR para Unid. Distribuição JOINVILLE - SC',
                'atualizado' => '05/10 08:41', 'alerta' => 'Previsto 06/10',
                'destinatario' => 'J. Almeida', 'cidade' => 'Joinville/SC', 'postagem' => '04/10/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '06/10/2026',
            ]),
            $o([
                'codigo' => 'OY991204563BR', 'servico' => 'SEDEX Contrato', 'situacao' => 'saiu_entrega', 'rotulo' => 'Saiu para entrega',
                'evento' => 'Objeto saiu para entrega ao destinatário', 'local' => 'Unidade de Distribuição · FLORIANOPOLIS - SC',
                'atualizado' => '05/10 07:55', 'alerta' => 'Previsto hoje',
                'destinatario' => 'Renata C.', 'cidade' => 'Florianópolis/SC', 'chamado' => 4530, 'postagem' => '02/10/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '05/10/2026',
            ]),
            $o([
                'codigo' => 'OY987663901BR', 'servico' => 'SEDEX Contrato', 'situacao' => 'entregue', 'rotulo' => 'Entregue',
                'evento' => 'Objeto entregue ao destinatário', 'local' => 'Unidade de Distribuição · SANTOS - SP',
                'atualizado' => '24/09 15:50', 'alerta' => 'Entregue no prazo',
                'destinatario' => 'Cliente Santos', 'cidade' => 'Santos/SP', 'chamado' => 4488, 'postagem' => '22/09/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '25/09/2026',
            ]),
            $o([
                'codigo' => 'YJ455021896BR', 'servico' => 'PAC Contrato', 'situacao' => 'entregue', 'rotulo' => 'Entregue',
                'evento' => 'Objeto entregue ao destinatário', 'local' => 'Unidade de Distribuição · CURITIBA - PR',
                'atualizado' => '03/10 11:18', 'alerta' => 'Entregue no prazo',
                'destinatario' => 'Escritório Batel', 'cidade' => 'Curitiba/PR', 'postagem' => '01/10/2026', 'postagem_local' => 'CURITIBA - PR', 'previsto' => '04/10/2026',
            ]),
        ];
    }

    /** Um objeto pelo código, com linha do tempo mínima quando o mockup não detalha. */
    public static function objeto(string $codigo): ?array
    {
        foreach (self::objetos() as $o) {
            if ($o['codigo'] !== $codigo) {
                continue;
            }
            if ($o['eventos'] === []) {
                [$data, $hora] = explode(' ', $o['atualizado']);
                $o['eventos'] = [
                    ['data' => $data, 'hora' => $hora, 'titulo' => $o['evento'], 'local' => $o['local'], 'cor' => self::corDaSituacao($o['situacao']), 'atual' => true],
                    ['data' => substr($o['postagem'], 0, 5), 'hora' => '16:00', 'titulo' => 'Objeto postado', 'local' => 'Agência dos Correios · ' . $o['postagem_local'], 'cor' => 'postado'],
                ];
            }
            $o['alertas'] ??= [];
            $o['prazo_retirada'] ??= null;
            $o['chamado_titulo'] ??= '';
            return $o;
        }

        return null;
    }

    /** @return list<string> */
    public static function codigos(): array
    {
        return array_column(self::objetos(), 'codigo');
    }

    /** Toasts de exemplo do mockup. */
    public static function toasts(): array
    {
        return [
            ['nivel' => 'atencao', 'icone' => 'ti ti-clock', 'titulo' => 'Prazo de retirada acabando', 'codigo' => 'QP302718234BR',
             'texto' => 'QP302718234BR aguarda retirada em LONDRINA/PR. Faltam 2 dias para a devolução ao remetente.'],
            ['nivel' => 'info', 'icone' => 'ti ti-truck', 'titulo' => 'Novo evento · há 3 min', 'codigo' => 'YJ460348417BR',
             'texto' => 'YJ460348417BR em transferência de CURITIBA/PR para JOINVILLE/SC.'],
        ];
    }

    private static function corDaSituacao(string $s): string
    {
        return match ($s) {
            'entregue'            => 'entregue',
            'saiu_entrega'        => 'saiu',
            'aguardando_retirada' => 'retirada',
            'problema', 'atrasado', 'sem_movimentacao' => 'problema',
            default               => 'transito',
        };
    }
}
