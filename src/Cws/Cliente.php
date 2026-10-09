<?php

/**
 * Postal+ — cliente CWS (Correios Web Services): token por cartão de postagem e teste de conexão.
 *
 * Token: POST {base}/token/v1/autentica/cartaopostagem, Basic (usuário Meu Correios : código de acesso),
 * corpo {"numero": cartão, "contrato": opcional}. Resposta 201 com token, expiraEm e cartaoPostagem.api.
 * O token é reaproveitado até 5 min antes de expirar.
 *
 * Nunca registrar usuário/código de acesso/token em log ou devolver para a tela.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus\Cws;

use GlpiPlugin\Postalplus\Configuracao;

class Cliente
{
    public const BASES = [
        'homologacao' => 'https://apihom.correios.com.br',
        'producao'    => 'https://api.correios.com.br',
    ];

    public const CAMINHO_TOKEN  = '/token/v1/autentica/cartaopostagem';
    public const CAMINHO_RASTRO = '/srorastro/v1/objetos/';

    /** Código de formato válido usado só para sondar se a API Rastro responde ao token. */
    public const CODIGO_SONDA = 'AA000000000BR';

    /** @var callable(string,string,array):void */
    private $gravarToken;

    /**
     * @param array{ambiente:string,usuario:string,codigo_acesso:string,cartao:string,contrato:string,token?:string,token_expira?:string} $cfg
     */
    public function __construct(
        private array $cfg,
        private Transporte $transporte,
        ?callable $gravarToken = null
    ) {
        $this->gravarToken = $gravarToken ?? static fn(string $t, string $e, array $a) => Configuracao::gravarToken($t, $e, $a);
    }

    /** Cliente com a configuração gravada no GLPI e o transporte adequado (real ou simulado). */
    public static function doGlpi(): self
    {
        $c = Configuracao::lerCru();

        return new self(
            [
                'ambiente'      => (string) $c['ambiente'],
                'usuario'       => (string) $c['cws_usuario'],
                'codigo_acesso' => Configuracao::segredo('cws_codigo_acesso'),
                'cartao'        => (string) $c['cws_cartao'],
                'contrato'      => (string) $c['cws_contrato'],
                'token'         => Configuracao::segredo('cws_token'),
                'token_expira'  => (string) $c['cws_token_expira'],
            ],
            self::simulado() ? new TransporteSimulado() : new TransporteCurl()
        );
    }

    public static function simulado(): bool
    {
        return defined('PLUGIN_POSTALPLUS_SIMULADO') && constant('PLUGIN_POSTALPLUS_SIMULADO') === true;
    }

    public function base(): string
    {
        return self::BASES[$this->cfg['ambiente']] ?? self::BASES['homologacao'];
    }

    /** @return list<string> campos obrigatórios que estão vazios */
    public function faltando(): array
    {
        $rotulos = [
            'usuario'       => 'Usuário (Meu Correios)',
            'codigo_acesso' => 'Código de acesso à API',
            'cartao'        => 'Cartão de postagem',
        ];
        $faltam = [];
        foreach ($rotulos as $campo => $rotulo) {
            if (trim((string) ($this->cfg[$campo] ?? '')) === '') {
                $faltam[] = $rotulo;
            }
        }

        return $faltam;
    }

    /**
     * Token válido (do cache ou novo).
     *
     * @return array{token:string, expira:string, novo:bool, apis:list<int>, contrato:string, cartao:string}
     * @throws CwsErro
     */
    public function obterToken(bool $forcar = false): array
    {
        $expira = (string) ($this->cfg['token_expira'] ?? '');
        if (!$forcar && ($this->cfg['token'] ?? '') !== '' && $expira !== '' && strtotime($expira) > time() + 300) {
            return ['token' => $this->cfg['token'], 'expira' => $expira, 'novo' => false, 'apis' => [], 'contrato' => '', 'cartao' => ''];
        }

        $faltam = $this->faltando();
        if ($faltam !== []) {
            throw new CwsErro('Preencha e salve: ' . implode(', ', $faltam) . '.', 'credenciais');
        }

        $corpo = ['numero' => $this->cfg['cartao']];
        if (($this->cfg['contrato'] ?? '') !== '') {
            $corpo['contrato'] = $this->cfg['contrato'];
        }

        $r = $this->transporte->requisitar('POST', $this->base() . self::CAMINHO_TOKEN, [
            'Authorization' => 'Basic ' . base64_encode($this->cfg['usuario'] . ':' . $this->cfg['codigo_acesso']),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], json_encode($corpo));

        if ($r['status'] === 0) {
            throw new CwsErro('Sem resposta de ' . parse_url($this->base(), PHP_URL_HOST) . ': ' . ($r['erro'] ?: 'falha de rede') . '.', 'rede');
        }

        $dados = json_decode($r['corpo'], true);
        if (!in_array($r['status'], [200, 201], true) || !is_array($dados) || empty($dados['token'])) {
            throw new CwsErro($this->mensagemErroToken($r['status'], is_array($dados) ? $dados : []), 'token', $r['status']);
        }

        $expiraEm = $this->normalizarData((string) ($dados['expiraEm'] ?? ''), (string) ($dados['zoneOffset'] ?? ''));
        $apis     = array_values(array_map('intval', (array) ($dados['cartaoPostagem']['api'] ?? [])));

        ($this->gravarToken)((string) $dados['token'], $expiraEm, $apis);
        $this->cfg['token']        = (string) $dados['token'];
        $this->cfg['token_expira'] = $expiraEm;

        return [
            'token'    => (string) $dados['token'],
            'expira'   => $expiraEm,
            'novo'     => true,
            'apis'     => $apis,
            'contrato' => (string) ($dados['cartaoPostagem']['contrato'] ?? ''),
            'cartao'   => (string) ($dados['cartaoPostagem']['numero'] ?? ''),
        ];
    }

    /**
     * "Testar conexão": gera token novo e sonda a API Rastro com um código fictício.
     *
     * @return array<string,mixed> resultado seguro para a tela (sem token nem credenciais)
     */
    public function testarConexao(): array
    {
        $res = [
            'ok'           => false,
            'ambiente'     => $this->cfg['ambiente'] === 'producao' ? 'Produção' : 'Homologação',
            'host'         => (string) parse_url($this->base(), PHP_URL_HOST),
            'simulado'     => self::simulado(),
            'token'        => null,
            'rastro'       => null,
            'erro'         => null,
        ];

        try {
            $t = $this->obterToken(true);
        } catch (CwsErro $e) {
            $res['erro'] = $e->getMessage();
            Configuracao::log('Testar conexão: falha no token (' . $e->tipo . ', HTTP ' . $e->status . ')');
            return $res;
        }

        $res['token'] = [
            'expira'   => $t['expira'],
            'contrato' => $t['contrato'],
            'cartao'   => $t['cartao'],
            'apis'     => $t['apis'],
        ];

        $res['rastro'] = $this->sondarRastro($t['token']);
        $res['ok']     = $res['rastro']['liberada'] === true;
        Configuracao::log('Testar conexão: token OK, Rastro HTTP ' . $res['rastro']['status']);

        return $res;
    }

    /** @return array{liberada:?bool, status:int, mensagem:string} */
    public function sondarRastro(string $token): array
    {
        $r = $this->transporte->requisitar(
            'GET',
            $this->base() . self::CAMINHO_RASTRO . self::CODIGO_SONDA . '?resultado=U',
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']
        );

        $dados = json_decode($r['corpo'], true);
        $msg   = is_array($dados) ? $this->primeiraMensagem($dados) : '';

        if ($r['status'] === 0) {
            return ['liberada' => null, 'status' => 0, 'mensagem' => 'Sem resposta: ' . ($r['erro'] ?: 'falha de rede')];
        }
        if (in_array($r['status'], [401, 403], true)) {
            return ['liberada' => false, 'status' => $r['status'], 'mensagem' => 'Token recusado pela API Rastro: verifique no CWS se a API Rastro está liberada para este cartão.' . ($msg !== '' ? " ($msg)" : '')];
        }
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return ['liberada' => true, 'status' => $r['status'], 'mensagem' => 'A API Rastro respondeu à consulta de teste.' . ($msg !== '' ? " ($msg)" : '')];
        }
        if ($r['status'] === 404 && is_array($dados) && isset($dados['objetos'])) {
            return ['liberada' => true, 'status' => 404, 'mensagem' => 'A API Rastro respondeu (objeto de teste não encontrado, como esperado).'];
        }

        return ['liberada' => null, 'status' => $r['status'], 'mensagem' => 'Resposta inesperada da API Rastro (HTTP ' . $r['status'] . ').' . ($msg !== '' ? " $msg" : '')];
    }

    private function mensagemErroToken(int $status, array $dados): string
    {
        $msg = $this->primeiraMensagem($dados);
        $base = match (true) {
            $status === 401 => 'Usuário ou código de acesso recusados pelos Correios (HTTP 401).',
            $status === 403 => 'Acesso negado (HTTP 403): o cartão de postagem pode não pertencer a este usuário ou estar inativo.',
            $status === 400 => 'Requisição recusada (HTTP 400): confira cartão e contrato.',
            $status >= 500  => 'Serviço de token dos Correios indisponível (HTTP ' . $status . '). Tente de novo em alguns minutos.',
            default         => 'Não foi possível gerar o token (HTTP ' . $status . ').',
        };

        return $msg !== '' ? "$base Correios: $msg" : $base;
    }

    private function primeiraMensagem(array $dados): string
    {
        $msg = '';
        if (!empty($dados['msgs']) && is_array($dados['msgs'])) {
            $msg = (string) reset($dados['msgs']);
        } elseif (!empty($dados['objetos'][0]['mensagem'])) {
            $msg = (string) $dados['objetos'][0]['mensagem'];
        } elseif (!empty($dados['mensagem'])) {
            $msg = (string) $dados['mensagem'];
        }

        return mb_substr(strip_tags($msg), 0, 200);
    }

    /** "2026-10-10T09:12:00" (+ zoneOffset) => "2026-10-10 09:12:00" no fuso do servidor. */
    private function normalizarData(string $data, string $offset): string
    {
        if ($data === '') {
            return date('Y-m-d H:i:s', time() + 3600);
        }
        try {
            $tz = preg_match('/^[+-]\d{2}:\d{2}$/', $offset) ? new \DateTimeZone($offset) : null;
            $dt = new \DateTimeImmutable($data, $tz);
            return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s', time() + 3600);
        }
    }
}
