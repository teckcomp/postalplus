<?php

/**
 * Postal+ — leitura, validação e gravação da configuração (glpi_configs, contexto plugin:postalplus).
 *
 * Regras:
 * - cws_codigo_acesso e cws_token são gravados cifrados pela GLPIKey (hook secured_configs).
 *   Config::getConfigurationValues() devolve o valor CIFRADO: decifrar só aqui, só para uso interno.
 * - O código de acesso NUNCA volta para a tela: o formulário só recebe "tem_codigo_acesso".
 *   Campo vazio no POST = manter o atual.
 * - Mudou usuário, código de acesso, cartão, contrato ou ambiente => token descartado.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use Config;
use GLPIKey;
use Toolbox;

class Configuracao
{
    /** Opções de frequência (minutos => rótulo). 480 dentro da janela 06–22 = 3 consultas/dia. */
    public const FREQUENCIAS = [
        15   => '15 minutos',
        30   => '30 minutos',
        60   => '1 hora',
        120  => '2 horas',
        240  => '4 horas',
        480  => '3 vezes ao dia',
        1440 => '1 vez ao dia',
    ];

    /** Situação do objeto => chave de frequência na configuração. */
    public const CHAVES_FREQUENCIA = [
        'freq_saiu_entrega'        => 'Saiu para entrega',
        'freq_transito'            => 'Em trânsito / postado',
        'freq_aguardando_retirada' => 'Aguardando retirada',
        'freq_problema'            => 'Problema / devolução',
    ];

    public const AMBIENTES = [
        'homologacao' => 'Homologação (cwshom)',
        'producao'    => 'Produção',
    ];

    /** Campos que, se mudarem, invalidam o token atual. */
    private const CAMPOS_DO_TOKEN = ['ambiente', 'cws_usuario', 'cws_codigo_acesso', 'cws_cartao', 'cws_contrato'];

    /** Configuração completa com valores padrão para chaves ausentes (valores CRUS: segredos cifrados). */
    public static function lerCru(): array
    {
        return Config::getConfigurationValues(Install::CONFIG_CONTEXT) + Install::configPadrao();
    }

    /**
     * Configuração segura para a tela: sem código de acesso e sem token.
     *
     * @return array<string,mixed>
     */
    public static function paraTela(): array
    {
        $c = self::lerCru();

        $perfis = json_decode((string) $c['alerta_perfis'], true);

        return [
            'ambiente'          => (string) $c['ambiente'],
            'cws_usuario'       => (string) $c['cws_usuario'],
            'cws_contrato'      => (string) $c['cws_contrato'],
            'cws_cartao'        => (string) $c['cws_cartao'],
            'tem_codigo_acesso' => (string) $c['cws_codigo_acesso'] !== '',
            'tem_token'         => (string) $c['cws_token'] !== '',
            'token_expira'      => (string) $c['cws_token_expira'],
            'token_valido'      => self::tokenValido($c),
            'freq'              => [
                'freq_saiu_entrega'        => (int) $c['freq_saiu_entrega'],
                'freq_transito'            => (int) $c['freq_transito'],
                'freq_aguardando_retirada' => (int) $c['freq_aguardando_retirada'],
                'freq_problema'            => (int) $c['freq_problema'],
            ],
            'janela_inicio'     => (string) $c['janela_inicio'],
            'janela_fim'        => (string) $c['janela_fim'],
            'webhook_n8n'       => (string) $c['webhook_n8n'],
            'alerta_perfis'     => is_array($perfis) ? array_map('intval', $perfis) : [],
            'email_equipe'      => (string) $c['email_equipe'],
        ];
    }

    /** Segredo decifrado (uso interno do cliente CWS). */
    public static function segredo(string $chave): string
    {
        $c     = Config::getConfigurationValues(Install::CONFIG_CONTEXT, [$chave]);
        $valor = (string) ($c[$chave] ?? '');
        if ($valor === '') {
            return '';
        }

        $decifrado = (new GLPIKey())->decrypt($valor);

        return is_string($decifrado) ? $decifrado : '';
    }

    public static function tokenValido(?array $c = null): bool
    {
        $c ??= self::lerCru();
        if ((string) $c['cws_token'] === '' || (string) $c['cws_token_expira'] === '') {
            return false;
        }

        // Margem de 5 minutos para não usar token prestes a expirar.
        return strtotime((string) $c['cws_token_expira']) > time() + 300;
    }

    /**
     * Valida e grava o formulário da tela de Configuração.
     *
     * @param array<string,mixed> $post
     * @return array{ok:bool, erros:list<string>, token_descartado:bool, reagendados:int}
     */
    public static function salvar(array $post): array
    {
        $atual = self::lerCru();
        $erros = [];
        $novo  = [];

        // Ambiente
        $ambiente = (string) ($post['ambiente'] ?? $atual['ambiente']);
        if (!array_key_exists($ambiente, self::AMBIENTES)) {
            $erros[] = 'Ambiente inválido.';
        } else {
            $novo['ambiente'] = $ambiente;
        }

        // Credenciais (texto simples)
        $novo['cws_usuario']  = trim((string) ($post['cws_usuario'] ?? ''));
        $novo['cws_contrato'] = preg_replace('/\D+/', '', (string) ($post['cws_contrato'] ?? ''));
        $novo['cws_cartao']   = preg_replace('/\D+/', '', (string) ($post['cws_cartao'] ?? ''));
        if (mb_strlen($novo['cws_usuario']) > 100) {
            $erros[] = 'Usuário do Meu Correios muito longo.';
        }
        if (strlen($novo['cws_contrato']) > 20 || strlen($novo['cws_cartao']) > 20) {
            $erros[] = 'Contrato e cartão de postagem aceitam só dígitos (até 20).';
        }

        // Código de acesso: vazio = manter; marcar "apagar" = limpar.
        $codigo = trim((string) ($post['cws_codigo_acesso'] ?? ''));
        if (!empty($post['apagar_codigo_acesso'])) {
            $novo['cws_codigo_acesso'] = '';
        } elseif ($codigo !== '') {
            if (mb_strlen($codigo) > 200) {
                $erros[] = 'Código de acesso muito longo.';
            } else {
                $novo['cws_codigo_acesso'] = $codigo;
            }
        }

        // Frequências
        foreach (array_keys(self::CHAVES_FREQUENCIA) as $chave) {
            $v = (int) ($post[$chave] ?? $atual[$chave]);
            if (!array_key_exists($v, self::FREQUENCIAS)) {
                $erros[] = 'Frequência inválida em ' . self::CHAVES_FREQUENCIA[$chave] . '.';
                continue;
            }
            $novo[$chave] = $v;
        }

        // Janela de consulta
        $ini = (string) ($post['janela_inicio'] ?? '');
        $fim = (string) ($post['janela_fim'] ?? '');
        if (!self::horaValida($ini) || !self::horaValida($fim)) {
            $erros[] = 'Janela de consulta: use o formato HH:MM.';
        } elseif ($ini >= $fim) {
            $erros[] = 'Janela de consulta: o início precisa ser antes do fim.';
        } else {
            $novo['janela_inicio'] = $ini;
            $novo['janela_fim']    = $fim;
        }

        // Webhook n8n
        $webhook = trim((string) ($post['webhook_n8n'] ?? ''));
        if ($webhook !== '' && (!filter_var($webhook, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $webhook))) {
            $erros[] = 'Webhook do n8n: informe uma URL http(s) completa.';
        } else {
            $novo['webhook_n8n'] = $webhook;
        }

        // Perfis que recebem alerta em tela
        $perfis = array_values(array_unique(array_filter(array_map('intval', (array) ($post['alerta_perfis'] ?? [])))));
        $novo['alerta_perfis'] = json_encode($perfis);

        // E-mail(s) da equipe — aceita lista separada por vírgula.
        $emails = array_values(array_filter(array_map('trim', explode(',', (string) ($post['email_equipe'] ?? '')))));
        foreach ($emails as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $erros[] = 'E-mail da equipe inválido: ' . $e;
            }
        }
        $novo['email_equipe'] = implode(', ', $emails);

        if ($erros !== []) {
            return ['ok' => false, 'erros' => $erros, 'token_descartado' => false, 'reagendados' => 0];
        }

        // Mudou algo que compõe o token? Descarta o token atual.
        $token_descartado = false;
        foreach (self::CAMPOS_DO_TOKEN as $campo) {
            if (!array_key_exists($campo, $novo)) {
                continue;
            }
            $antes = $campo === 'cws_codigo_acesso' ? self::segredo($campo) : (string) $atual[$campo];
            if ((string) $novo[$campo] !== $antes) {
                $token_descartado = true;
                break;
            }
        }
        if ($token_descartado && (string) $atual['cws_token'] !== '') {
            $novo['cws_token']        = '';
            $novo['cws_token_expira'] = '';
            $novo['cws_apis']         = '[]';
        }

        Config::setConfigurationValues(Install::CONFIG_CONTEXT, $novo);

        // Bloco 4: mudou alguma frequência? Reagenda quem está em acompanhamento (vale já, não só na próxima consulta).
        $reagendados = 0;
        foreach (array_keys(self::CHAVES_FREQUENCIA) as $chave) {
            if ((int) $novo[$chave] !== (int) $atual[$chave]) {
                $reagendados = Rastreio::reagendar($novo + $atual);
                break;
            }
        }

        return ['ok' => true, 'erros' => [], 'token_descartado' => $token_descartado && (string) $atual['cws_token'] !== '', 'reagendados' => $reagendados];
    }

    /** Grava um token obtido da API (cifrado pela GLPIKey via secured_configs). */
    public static function gravarToken(string $token, string $expira, array $apis): void
    {
        Config::setConfigurationValues(Install::CONFIG_CONTEXT, [
            'cws_token'        => $token,
            'cws_token_expira' => $expira,
            'cws_apis'         => json_encode(array_values(array_map('intval', $apis))),
        ]);
    }

    /**
     * Valida o cartão "Chamado automático" da tela Regras (categoria, grupo, prioridade).
     *
     * @return list<string>
     */
    public static function validarChamado(array $post): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $erros = [];
        $prio  = (int) ($post['chamado_prioridade'] ?? 0);
        if ($prio < 1 || $prio > 6) {
            $erros[] = 'Chamado automático: prioridade inválida.';
        }
        $cat = (int) ($post['chamado_itilcategories_id'] ?? 0);
        if ($cat > 0 && count($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['id' => $cat]])) === 0) {
            $erros[] = 'Chamado automático: categoria não encontrada.';
        }
        $grp = (int) ($post['chamado_groups_id'] ?? 0);
        if ($grp > 0 && count($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $grp]])) === 0) {
            $erros[] = 'Chamado automático: grupo não encontrado.';
        }

        return $erros;
    }

    /** Grava o padrão do chamado automático (chamar só depois de validarChamado). */
    public static function salvarChamado(array $post): void
    {
        Config::setConfigurationValues(Install::CONFIG_CONTEXT, [
            'chamado_itilcategories_id' => max(0, (int) ($post['chamado_itilcategories_id'] ?? 0)),
            'chamado_groups_id'         => max(0, (int) ($post['chamado_groups_id'] ?? 0)),
            'chamado_prioridade'        => (int) ($post['chamado_prioridade'] ?? 4),
        ]);
    }

    private static function horaValida(string $h): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);
    }

    /** Log do plugin em files/_log/postalplus.log — nunca registrar credenciais. */
    public static function log(string $msg): void
    {
        Toolbox::logInFile('postalplus', "$msg\n", true, false);
    }
}
