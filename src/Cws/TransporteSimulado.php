<?php

/**
 * Postal+ — simulador das respostas do CWS (token e Rastro) para desenvolvimento.
 *
 * Ativado só quando PLUGIN_POSTALPLUS_SIMULADO está definido como true
 * (ex.: em config/local_define.php do GLPI de desenvolvimento). NUNCA em homologação/produção.
 *
 * Credenciais de teste: usuário "recusar" => 401; cartão "0000000000" => 403.
 *
 * Rastro (Bloco 3): o cenário vem do ÚLTIMO DÍGITO do número do código (AA12345678 9 BR):
 *   0 postado · 1 em trânsito · 2 saiu para entrega · 3 aguardando retirada · 4 carteiro não atendido
 *   5 entregue · 6 em devolução · 7 não encontrado (SRO-020) · 8 extraviado · 9 endereço incorreto
 * As datas partem do cadastro do objeto (date_creation); o último evento só "acontece" 3 minutos
 * depois do cadastro — assim a segunda consulta mostra 1 evento novo. A cidade de destino vem do
 * campo Cidade/UF do objeto. AA000000000BR (sonda do "Testar conexão") é sempre não encontrado.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus\Cws;

class TransporteSimulado implements Transporte
{
    public function requisitar(string $metodo, string $url, array $cabecalhos, ?string $corpo = null): array
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);

        if ($metodo === 'POST' && str_ends_with($caminho, '/token/v1/autentica/cartaopostagem')) {
            return $this->token($cabecalhos, $corpo);
        }

        if ($metodo === 'GET' && preg_match('#/srorastro/v1/objetos/([A-Z]{2}\d{9}[A-Z]{2})$#', $caminho, $m)) {
            if (!str_starts_with($cabecalhos['Authorization'] ?? '', 'Bearer ')) {
                return $this->json(401, ['msgs' => ['Token ausente']]);
            }
            return $this->json(200, [
                'versao'     => '2.1.3',
                'quantidade' => 1,
                'resultado'  => str_contains((string) parse_url($url, PHP_URL_QUERY), 'resultado=T') ? 'Todos os Eventos' : 'Último evento',
                'objetos'    => [$this->objeto($m[1])],
            ]);
        }

        return $this->json(404, ['msgs' => ['Recurso não encontrado (simulador)']]);
    }

    private function token(array $cabecalhos, ?string $corpo): array
    {
        $basic = base64_decode(substr($cabecalhos['Authorization'] ?? '', 6)) ?: '';
        [$usuario] = explode(':', $basic, 2) + [''];
        $dados  = json_decode((string) $corpo, true) ?: [];
        $cartao = (string) ($dados['numero'] ?? '');

        if ($usuario === '' || $usuario === 'recusar') {
            return $this->json(401, ['msgs' => ['Usuário ou senha inválidos (simulador)']]);
        }
        if ($cartao === '0000000000') {
            return $this->json(403, ['msgs' => ['Cartão de postagem não pertence ao usuário (simulador)']]);
        }

        $emissao = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        return $this->json(201, [
            'ambiente'       => 'HOMOLOGACAO',
            'id'             => $usuario,
            'perfil'         => 'PJ',
            'cnpj'           => '00000000000000',
            'emissao'        => $emissao->format('Y-m-d\TH:i:s'),
            'expiraEm'       => $emissao->modify('+1 day')->format('Y-m-d\TH:i:s'),
            'zoneOffset'     => '-03:00',
            'token'          => 'SIMULADO.' . bin2hex(random_bytes(16)),
            'cartaoPostagem' => [
                'numero'   => $cartao,
                'contrato' => (string) ($dados['contrato'] ?? '9912345678'),
                'dr'       => 20,
                'api'      => [27, 34, 35, 36, 37, 41, 76, 78, 87],
            ],
        ]);
    }

    /** Objeto simulado no formato da API Rastro (eventos do mais recente para o mais antigo). */
    public function objeto(string $codigo, ?int $ancora = null, string $cidadeUf = ''): array
    {
        $cenario = (int) substr($codigo, 10, 1);
        if ($codigo === 'AA000000000BR' || $cenario === 7) {
            return ['codObjeto' => $codigo, 'mensagem' => 'SRO-020: Objeto não encontrado na base de dados dos Correios.'];
        }

        if ($ancora === null) {
            [$ancora, $cidadeUf] = $this->dadosDoCadastro($codigo);
        }
        [$cidade, $uf] = $this->cidadeUf($cidadeUf);

        $ag    = static fn(string $c, string $u) => ['tipo' => 'Agência dos Correios', 'endereco' => ['cidade' => $c, 'uf' => $u]];
        $ut    = ['tipo' => 'Unidade de Tratamento', 'endereco' => ['cidade' => 'CURITIBA', 'uf' => 'PR']];
        $ud    = ['tipo' => 'Unidade de Distribuição', 'endereco' => ['cidade' => $cidade, 'uf' => $uf]];
        $ev    = static fn(string $cod, string $tipo, int $seg, string $desc, array $un, array $extra = []) => [
            'codigo' => $cod, 'tipo' => $tipo, 'dtHrCriado' => date('Y-m-d\TH:i:s', $ancora + $seg), 'descricao' => $desc, 'unidade' => $un,
        ] + $extra;
        $h     = 3600;
        $depois = 180; // último evento: 3 minutos depois do cadastro

        $postado  = $ev('PO', '01', -72 * $h, 'Objeto postado', $ag('CURITIBA', 'PR'));
        $transito = $ev('RO', '01', -50 * $h, 'Objeto em transferência - por favor aguarde', $ut, ['unidadeDestino' => $ud]);
        $saiu     = $ev('OEC', '01', -26 * $h, 'Objeto saiu para entrega ao destinatário', $ud);
        $naoAt    = $ev('BDE', '20', -20 * $h, 'A entrega não pode ser efetuada - Carteiro não atendido', $ud);

        $lista = match ($cenario) {
            0 => [$ev('PO', '01', -2 * $h, 'Objeto postado', $ag('CURITIBA', 'PR'))],
            1 => [$postado, $ev('RO', '01', $depois, 'Objeto em transferência - por favor aguarde', $ut, ['unidadeDestino' => $ud])],
            2 => [$postado, $transito, $ev('OEC', '01', $depois, 'Objeto saiu para entrega ao destinatário', $ud)],
            3 => [$postado, $transito, $saiu, $naoAt, $ev('LDI', '01', $depois, 'Objeto aguardando retirada no endereço indicado', $ag($cidade, $uf),
                    ['detalhe' => 'Para retirá-lo é preciso informar o código do objeto e apresentar documento de identificação com foto.'])],
            4 => [$postado, $transito, $saiu, $ev('BDE', '20', $depois, 'A entrega não pode ser efetuada - Carteiro não atendido', $ud)],
            5 => [$postado, $transito, $saiu, $ev('BDE', '01', $depois, 'Objeto entregue ao destinatário', $ud)],
            6 => [$postado, $transito, $saiu, $naoAt, $ev('FC', '10', $depois, 'Objeto em devolução ao remetente', $ag($cidade, $uf))],
            8 => [$postado, $ev('BDI', '09', $depois, 'Objeto extraviado', $ut)],
            default => [$postado, $transito, $saiu, $ev('BDE', '03', $depois, 'A entrega não pode ser efetuada - Endereço incorreto', $ud)],
        };

        $agora = time();
        $lista = array_values(array_filter($lista, static fn($e) => strtotime($e['dtHrCriado']) <= $agora));
        usort($lista, static fn($a, $b) => strcmp($b['dtHrCriado'], $a['dtHrCriado']));

        $pac = in_array(substr($codigo, 0, 2), ['QP', 'PJ', 'PL'], true);

        return [
            'codObjeto'  => $codigo,
            'tipoPostal' => ['sigla' => substr($codigo, 0, 2), 'descricao' => $pac ? 'PAC' : 'SEDEX', 'categoria' => 'ENCOMENDA'],
            'dtPrevista' => date('Y-m-d\T23:59:59', $ancora + ($pac ? 5 : 2) * 86400),
            'contrato'   => '9912345678',
            'eventos'    => $lista,
        ];
    }

    /** @return array{0:int, 1:string} cadastro do objeto (date_creation, cidade/UF) ou agora */
    private function dadosDoCadastro(string $codigo): array
    {
        global $DB;
        try {
            $r = $DB->request([
                'SELECT' => ['date_creation', 'destinatario_cidade'],
                'FROM'   => 'glpi_plugin_postalplus_objetos',
                'WHERE'  => ['codigo' => $codigo],
            ])->current();
        } catch (\Throwable) {
            $r = null;
        }
        $ts = !empty($r['date_creation']) ? strtotime((string) $r['date_creation']) : false;

        return [$ts ?: time(), (string) ($r['destinatario_cidade'] ?? '')];
    }

    /** "Londrina/PR", "curitiba pr", "Maringá - PR" => [MARINGA, PR]; padrão LONDRINA/PR. */
    private function cidadeUf(string $txt): array
    {
        $txt = trim($txt);
        if (preg_match('/^(.+?)[\s\/\-]+([A-Za-z]{2})$/u', $txt, $m)) {
            return [strtoupper(\GlpiPlugin\Postalplus\Situacao::semAcento(mb_strtolower(trim($m[1])))), strtoupper($m[2])];
        }

        return $txt !== '' ? [strtoupper(\GlpiPlugin\Postalplus\Situacao::semAcento(mb_strtolower($txt))), 'PR'] : ['LONDRINA', 'PR'];
    }

    private function json(int $status, array $dados): array
    {
        return ['status' => $status, 'corpo' => json_encode($dados), 'erro' => null];
    }
}
