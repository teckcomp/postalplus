<?php

/**
 * Postal+ — instalação idempotente (schema, direitos, configuração padrão, regras).
 *
 * Regra do projeto: migração de schema SÓ aqui, sempre com tableExists/fieldExists.
 * Higiene de dados não entra no Install.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use Config;
use DBConnection;
use Migration;
use ProfileRight;

class Install
{
    public const CONFIG_CONTEXT = 'plugin:postalplus';

    /** Tabelas na ordem de criação (a remoção usa a ordem inversa). */
    public const TABELAS = [
        'glpi_plugin_postalplus_objetos',
        'glpi_plugin_postalplus_eventos',
        'glpi_plugin_postalplus_alertas',
        'glpi_plugin_postalplus_alertaleituras',
        'glpi_plugin_postalplus_regras',
        'glpi_plugin_postalplus_consultas',
    ];

    public static function install(Migration $migration): void
    {
        self::criarTabelas($migration);
        self::criarDireitos($migration);
        self::criarConfigPadrao();
        self::criarRegrasPadrao();
    }

    public static function uninstall(): void
    {
        global $DB;

        foreach (array_reverse(self::TABELAS) as $tabela) {
            if ($DB->tableExists($tabela)) {
                $DB->doQuery("DROP TABLE `$tabela`");
            }
        }

        $config = new Config();
        $config->deleteConfigurationValues(self::CONFIG_CONTEXT, array_keys(self::configPadrao()));

        ProfileRight::deleteProfileRights(array_keys(PerfilDireitos::getDireitos()));
    }

    /**
     * Valores padrão de configuração (contexto plugin:postalplus na glpi_configs).
     * cws_codigo_acesso e cws_token são criptografados pela GLPIKey (hook secured_configs).
     * Frequências em minutos.
     *
     * @return array<string,string|int>
     */
    public static function configPadrao(): array
    {
        return [
            'ambiente'                  => 'homologacao',
            'cws_usuario'               => '',
            'cws_codigo_acesso'         => '',
            'cws_contrato'              => '',
            'cws_cartao'                => '',
            'cws_token'                 => '',
            'cws_token_expira'          => '',
            'cws_apis'                  => '[]',
            'freq_saiu_entrega'         => 30,
            'freq_transito'             => 60,
            'freq_aguardando_retirada'  => 480,
            'freq_problema'             => 60,
            'janela_inicio'             => '06:00',
            'janela_fim'                => '22:00',
            'webhook_n8n'               => '',
            'alerta_perfis'             => '[]',
            'email_equipe'              => '',
            'chamado_itilcategories_id' => 0,
            'chamado_groups_id'         => 0,
            'chamado_prioridade'        => 4,
            'toast_intervalo'           => 45,
        ];
    }

    private static function criarTabelas(Migration $migration): void
    {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $opts      = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        // Objetos rastreados (um por código).
        $t = 'glpi_plugin_postalplus_objetos';
        if (!$DB->tableExists($t)) {
            $migration->displayMessage("Criando $t");
            $DB->doQuery("CREATE TABLE `$t` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `codigo` varchar(13) NOT NULL,
                `servico` varchar(100) DEFAULT NULL,
                `data_postagem` date DEFAULT NULL,
                `destinatario_nome` varchar(255) DEFAULT NULL,
                `destinatario_contato` varchar(255) DEFAULT NULL,
                `destinatario_whatsapp` varchar(30) DEFAULT NULL,
                `destinatario_email` varchar(255) DEFAULT NULL,
                `destinatario_cidade` varchar(255) DEFAULT NULL,
                `tickets_id` int {$sign} NOT NULL DEFAULT 0,
                `users_id` int {$sign} NOT NULL DEFAULT 0,
                `groups_id` int {$sign} NOT NULL DEFAULT 0,
                `situacao` varchar(30) NOT NULL DEFAULT 'nao_consultado',
                `ultimo_evento_codigo` varchar(10) DEFAULT NULL,
                `ultimo_evento_tipo` varchar(10) DEFAULT NULL,
                `ultimo_evento_descricao` varchar(255) DEFAULT NULL,
                `ultimo_evento_local` varchar(255) DEFAULT NULL,
                `ultimo_evento_data` timestamp NULL DEFAULT NULL,
                `prazo_previsto` date DEFAULT NULL,
                `prazo_retirada` date DEFAULT NULL,
                `ultima_consulta` timestamp NULL DEFAULT NULL,
                `proxima_consulta` timestamp NULL DEFAULT NULL,
                `erro_consulta` varchar(255) DEFAULT NULL,
                `avisar_whatsapp` tinyint NOT NULL DEFAULT 0,
                `abrir_chamado` tinyint NOT NULL DEFAULT 1,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `codigo` (`codigo`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `tickets_id` (`tickets_id`),
                KEY `users_id` (`users_id`),
                KEY `groups_id` (`groups_id`),
                KEY `situacao` (`situacao`),
                KEY `proxima_consulta` (`proxima_consulta`),
                KEY `is_active` (`is_active`),
                KEY `is_deleted` (`is_deleted`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) {$opts}");
        }

        // 0.2.0 (Bloco 2): "Responsável pelo recebimento" — destinatario_nome passa a ser a empresa/cliente.
        if (!$DB->fieldExists($t, 'destinatario_contato')) {
            $migration->displayMessage("Adicionando $t.destinatario_contato");
            $migration->addField($t, 'destinatario_contato', 'string', ['after' => 'destinatario_nome']);
        }

        // Eventos SRO devolvidos pela API Rastro (hash evita duplicar a cada consulta).
        $t = 'glpi_plugin_postalplus_eventos';
        if (!$DB->tableExists($t)) {
            $migration->displayMessage("Criando $t");
            $DB->doQuery("CREATE TABLE `$t` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_postalplus_objetos_id` int {$sign} NOT NULL DEFAULT 0,
                `codigo` varchar(10) DEFAULT NULL,
                `tipo` varchar(10) DEFAULT NULL,
                `descricao` varchar(255) DEFAULT NULL,
                `detalhe` text,
                `unidade_nome` varchar(255) DEFAULT NULL,
                `unidade_cidade` varchar(255) DEFAULT NULL,
                `unidade_uf` varchar(2) DEFAULT NULL,
                `destino_nome` varchar(255) DEFAULT NULL,
                `destino_cidade` varchar(255) DEFAULT NULL,
                `destino_uf` varchar(2) DEFAULT NULL,
                `data_evento` timestamp NULL DEFAULT NULL,
                `hash` char(40) NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `objeto_hash` (`plugin_postalplus_objetos_id`, `hash`),
                KEY `data_evento` (`data_evento`),
                KEY `date_creation` (`date_creation`)
            ) {$opts}");
        }

        // Alertas disparados pelo motor de regras.
        $t = 'glpi_plugin_postalplus_alertas';
        if (!$DB->tableExists($t)) {
            $migration->displayMessage("Criando $t");
            $DB->doQuery("CREATE TABLE `$t` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_postalplus_objetos_id` int {$sign} NOT NULL DEFAULT 0,
                `regra` varchar(40) NOT NULL,
                `nivel` varchar(20) NOT NULL DEFAULT 'info',
                `titulo` varchar(255) DEFAULT NULL,
                `mensagem` text,
                `canais` varchar(255) DEFAULT NULL,
                `tickets_id` int {$sign} NOT NULL DEFAULT 0,
                `resultado_envio` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_postalplus_objetos_id` (`plugin_postalplus_objetos_id`),
                KEY `regra` (`regra`),
                KEY `tickets_id` (`tickets_id`),
                KEY `date_creation` (`date_creation`)
            ) {$opts}");
        }

        // Quem já viu/fechou cada alerta em tela.
        $t = 'glpi_plugin_postalplus_alertaleituras';
        if (!$DB->tableExists($t)) {
            $migration->displayMessage("Criando $t");
            $DB->doQuery("CREATE TABLE `$t` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_postalplus_alertas_id` int {$sign} NOT NULL DEFAULT 0,
                `users_id` int {$sign} NOT NULL DEFAULT 0,
                `date_read` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `alerta_usuario` (`plugin_postalplus_alertas_id`, `users_id`),
                KEY `users_id` (`users_id`)
            ) {$opts}");
        }

        // Regras de alerta (uma linha por regra fixa da tela "Regras de alerta").
        $t = 'glpi_plugin_postalplus_regras';
        if (!$DB->tableExists($t)) {
            $migration->displayMessage("Criando $t");
            $DB->doQuery("CREATE TABLE `$t` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `chave` varchar(40) NOT NULL,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `parametro` int DEFAULT NULL,
                `canal_tela` tinyint NOT NULL DEFAULT 1,
                `canal_email` tinyint NOT NULL DEFAULT 0,
                `canal_whatsapp` tinyint NOT NULL DEFAULT 0,
                `canal_chamado` tinyint NOT NULL DEFAULT 0,
                `eventos` text,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `chave` (`chave`),
                KEY `is_active` (`is_active`)
            ) {$opts}");
        }

        // Log das execuções de consulta (cron e "Consultar agora").
        $t = 'glpi_plugin_postalplus_consultas';
        if (!$DB->tableExists($t)) {
            $migration->displayMessage("Criando $t");
            $DB->doQuery("CREATE TABLE `$t` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `origem` varchar(20) NOT NULL DEFAULT 'cron',
                `date_start` timestamp NULL DEFAULT NULL,
                `date_end` timestamp NULL DEFAULT NULL,
                `objetos` int NOT NULL DEFAULT 0,
                `novos_eventos` int NOT NULL DEFAULT 0,
                `alertas` int NOT NULL DEFAULT 0,
                `erros` int NOT NULL DEFAULT 0,
                `resultado` varchar(255) DEFAULT NULL,
                `detalhe` text,
                PRIMARY KEY (`id`),
                KEY `date_start` (`date_start`),
                KEY `origem` (`origem`)
            ) {$opts}");
        }
    }

    private static function criarDireitos(Migration $migration): void
    {
        // addRight é idempotente e só concede a perfis que já têm "Configurar" (config R+U).
        foreach (PerfilDireitos::getDireitos() as $nome => $valor) {
            $migration->addRight($nome, $valor);
        }
    }

    private static function criarConfigPadrao(): void
    {
        $atuais   = Config::getConfigurationValues(self::CONFIG_CONTEXT);
        $faltando = array_diff_key(self::configPadrao(), $atuais);

        if ($faltando !== []) {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, $faltando);
        }
    }

    /**
     * Regras padrão conforme o mockup aprovado. Só insere a regra que ainda não existe:
     * reinstalar NÃO desfaz ajustes feitos na tela.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function regrasPadrao(): array
    {
        return [
            'aguardando_retirada' => [
                'parametro' => 2, 'canal_tela' => 1, 'canal_email' => 1, 'canal_whatsapp' => 1, 'canal_chamado' => 0,
            ],
            'sem_movimentacao' => [
                'parametro' => 5, 'canal_tela' => 1, 'canal_email' => 1, 'canal_whatsapp' => 0, 'canal_chamado' => 1,
            ],
            'atraso' => [
                'parametro' => 1, 'canal_tela' => 1, 'canal_email' => 1, 'canal_whatsapp' => 0, 'canal_chamado' => 0,
            ],
            'eventos_criticos' => [
                'parametro' => null, 'canal_tela' => 1, 'canal_email' => 1, 'canal_whatsapp' => 0, 'canal_chamado' => 1,
                'eventos'   => json_encode([
                    'carteiro_nao_atendido',
                    'endereco_incorreto',
                    'destinatario_mudou',
                    'recusado',
                    'devolucao',
                    'extraviado',
                    'avariado',
                ]),
            ],
            'entrega_confirmada' => [
                // canal_chamado aqui significa "solucionar o chamado vinculado".
                'parametro' => null, 'canal_tela' => 1, 'canal_email' => 0, 'canal_whatsapp' => 1, 'canal_chamado' => 1,
            ],
        ];
    }

    private static function criarRegrasPadrao(): void
    {
        global $DB;

        $t = 'glpi_plugin_postalplus_regras';
        foreach (self::regrasPadrao() as $chave => $valores) {
            $existe = $DB->request(['COUNT' => 'cpt', 'FROM' => $t, 'WHERE' => ['chave' => $chave]])->current();
            if ((int) ($existe['cpt'] ?? 0) > 0) {
                continue;
            }
            $DB->insert($t, ['chave' => $chave, 'is_active' => 1, 'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')] + $valores);
        }
    }
}
