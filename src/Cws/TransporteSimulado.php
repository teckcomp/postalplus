<?php

/**
 * Postal+ — simulador das respostas do CWS (token e Rastro) para desenvolvimento.
 *
 * Ativado só quando PLUGIN_POSTALPLUS_SIMULADO está definido como true
 * (ex.: em config/local_define.php do GLPI de desenvolvimento). NUNCA em homologação/produção.
 *
 * Credenciais de teste: usuário "recusar" => 401; cartão "0000000000" => 403.
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
                'resultado'  => 'Último evento',
                'objetos'    => [['codObjeto' => $m[1], 'mensagem' => 'SRO-020: Objeto não encontrado na base de dados dos Correios.']],
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

    private function json(int $status, array $dados): array
    {
        return ['status' => $status, 'corpo' => json_encode($dados), 'erro' => null];
    }
}
