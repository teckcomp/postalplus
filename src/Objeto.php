<?php

/**
 * Postal+ — objeto rastreado (um por código de rastreio).
 *
 * Bloco 2: cadastro individual. Destinatário = cliente/empresa; "Responsável pelo recebimento"
 * (destinatario_contato) = pessoa que recebe no cliente — WhatsApp e e-mail são dessa pessoa.
 * O objeto nasce em situação "nao_consultado"; a consulta à API Rastro (Bloco 3, classe Rastreio)
 * grava eventos e atualiza situação, último evento e prazos.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use CommonDBTM;
use Dropdown;
use Group;
use Session;
use Ticket;
use User;

class Objeto extends CommonDBTM
{
    public static $rightname = PerfilDireitos::RIGHT_OBJETO;

    public $dohistory = false;

    /** Serviços oferecidos no cadastro (texto livre na tabela; a API Rastro confirma no Bloco 3). */
    public const SERVICOS = ['PAC Contrato', 'SEDEX Contrato', 'SEDEX 10 Contrato', 'Carta Registrada'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Objetos rastreados' : 'Objeto rastreado';
    }

    public static function getIcon()
    {
        return 'ti ti-package';
    }

    /** Código em maiúsculas, sem espaços. */
    public static function normalizarCodigo(string $codigo): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $codigo));
    }

    /**
     * WhatsApp só com dígitos (DDD + número, 10 ou 11 dígitos). Aceita +55 na frente.
     * Devolve '' para vazio e null para inválido.
     */
    public static function normalizarWhatsapp(string $fone): ?string
    {
        $d = (string) preg_replace('/\D+/', '', $fone);
        if ($d === '') {
            return '';
        }
        if (strlen($d) >= 12 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 12 && $d[0] === '0') {
            $d = substr($d, 1);
        }

        return preg_match('/^[1-9]{2}\d{8,9}$/', $d) ? $d : null;
    }

    /** (43) 99999-0021 / (41) 3333-4444 */
    public static function formatarWhatsapp(?string $d): string
    {
        $d = (string) $d;
        if (!preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $d, $m)) {
            return $d;
        }

        return "({$m[1]}) {$m[2]}-{$m[3]}";
    }

    public static function codigoExiste(string $codigo): bool
    {
        return countElementsInTable(self::getTable(), ['codigo' => $codigo]) > 0;
    }

    /** Valores iniciais do formulário "Objeto individual". */
    public static function formularioPadrao(): array
    {
        return [
            'codigo'                => '',
            'servico'               => self::SERVICOS[0],
            'data_postagem'         => '',
            'destinatario_nome'     => '',
            'destinatario_contato'  => '',
            'destinatario_whatsapp' => '',
            'destinatario_email'    => '',
            'destinatario_cidade'   => '',
            'tickets_id'            => '',
            'entities_id'           => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'users_id'              => (int) Session::getLoginUserID(),
            'groups_id'             => 0,
            'avisar_whatsapp'       => 1,
            'abrir_chamado'         => 1,
        ];
    }

    /**
     * Valida o POST do formulário individual.
     *
     * @return array{0: array<string,mixed>, 1: list<string>, 2: array<string,mixed>}
     *         [dados prontos para add(), erros, valores para reexibir o formulário]
     */
    public static function validarFormulario(array $post): array
    {
        $txt = static fn(string $k, int $max = 255): string => mb_substr(trim((string) ($post[$k] ?? '')), 0, $max);

        $form = [
            'codigo'                => self::normalizarCodigo((string) ($post['codigo'] ?? '')),
            'servico'               => $txt('servico', 100),
            'data_postagem'         => $txt('data_postagem', 10),
            'destinatario_nome'     => $txt('destinatario_nome'),
            'destinatario_contato'  => $txt('destinatario_contato'),
            'destinatario_whatsapp' => $txt('destinatario_whatsapp', 30),
            'destinatario_email'    => $txt('destinatario_email'),
            'destinatario_cidade'   => $txt('destinatario_cidade'),
            'tickets_id'            => ltrim($txt('tickets_id', 12), '#'),
            'entities_id'           => (int) ($post['entities_id'] ?? -1),
            'users_id'              => (int) ($post['users_id'] ?? 0),
            'groups_id'             => (int) ($post['groups_id'] ?? 0),
            'avisar_whatsapp'       => empty($post['avisar_whatsapp']) ? 0 : 1,
            'abrir_chamado'         => empty($post['abrir_chamado']) ? 0 : 1,
        ];
        $erros = [];

        // Código
        if ($form['codigo'] === '') {
            $erros[] = 'Informe o código de rastreio.';
        } elseif (!Situacao::codigoValido($form['codigo'])) {
            $erros[] = "Código {$form['codigo']} com formato inválido: 2 letras + 9 dígitos + 2 letras (ex.: AA123456789BR).";
        } elseif (self::codigoExiste($form['codigo'])) {
            $erros[] = "O objeto {$form['codigo']} já está cadastrado.";
        }

        // Serviço (vazio = a API informa depois)
        if ($form['servico'] !== '' && !in_array($form['servico'], self::SERVICOS, true)) {
            $erros[] = 'Serviço inválido.';
        }

        // Data de postagem
        $data = null;
        if ($form['data_postagem'] !== '') {
            $dt = \DateTime::createFromFormat('!Y-m-d', $form['data_postagem']);
            if (!$dt || $dt->format('Y-m-d') !== $form['data_postagem']) {
                $erros[] = 'Data de postagem inválida.';
            } elseif ($dt->format('Y-m-d') > date('Y-m-d', strtotime($_SESSION['glpi_currenttime'] ?? 'now'))) {
                $erros[] = 'A data de postagem não pode ser no futuro.';
            } else {
                $data = $dt->format('Y-m-d');
            }
        }

        // WhatsApp e e-mail do responsável pelo recebimento
        $fone = self::normalizarWhatsapp($form['destinatario_whatsapp']);
        if ($fone === null) {
            $erros[] = 'WhatsApp inválido: informe DDD + número, ex.: (43) 99999-0021.';
        } elseif ($fone === '' && $form['avisar_whatsapp']) {
            $erros[] = 'Para avisar por WhatsApp, informe o WhatsApp do responsável pelo recebimento (ou desmarque o aviso).';
        }
        if ($form['destinatario_email'] !== '' && !filter_var($form['destinatario_email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'E-mail do responsável pelo recebimento inválido.';
        }

        // Chamado vinculado
        $tickets_id = 0;
        if ($form['tickets_id'] !== '') {
            if (!ctype_digit($form['tickets_id']) || (int) $form['tickets_id'] <= 0) {
                $erros[] = 'Chamado vinculado: informe só o número.';
            } else {
                $ticket = new Ticket();
                if (!$ticket->getFromDB((int) $form['tickets_id']) || $ticket->isDeleted()) {
                    $erros[] = "Chamado #{$form['tickets_id']} não encontrado.";
                } elseif (!$ticket->canViewItem()) {
                    $erros[] = "Você não tem acesso ao chamado #{$form['tickets_id']}.";
                } else {
                    $tickets_id = (int) $form['tickets_id'];
                }
            }
        }

        // Entidade, responsável, grupo
        $ativas = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        if (!in_array($form['entities_id'], $ativas, true)) {
            $erros[] = 'Entidade inválida ou fora das entidades ativas.';
        }
        if ($form['users_id'] > 0) {
            $u = new User();
            if (!$u->getFromDB($form['users_id']) || !$u->fields['is_active'] || $u->fields['is_deleted']) {
                $erros[] = 'Responsável não encontrado ou inativo.';
            }
        }
        if ($form['groups_id'] > 0) {
            $g = new Group();
            if (!$g->getFromDB($form['groups_id']) || !$g->fields['is_assign']) {
                $erros[] = 'Grupo não encontrado ou não pode receber atribuições.';
            }
        }

        $dados = [
            'codigo'                => $form['codigo'],
            'servico'               => $form['servico'] !== '' ? $form['servico'] : null,
            'data_postagem'         => $data,
            'destinatario_nome'     => $form['destinatario_nome'] !== '' ? $form['destinatario_nome'] : null,
            'destinatario_contato'  => $form['destinatario_contato'] !== '' ? $form['destinatario_contato'] : null,
            'destinatario_whatsapp' => $fone !== '' ? $fone : null,
            'destinatario_email'    => $form['destinatario_email'] !== '' ? $form['destinatario_email'] : null,
            'destinatario_cidade'   => $form['destinatario_cidade'] !== '' ? $form['destinatario_cidade'] : null,
            'tickets_id'            => $tickets_id,
            'entities_id'           => $form['entities_id'],
            'users_id'              => max(0, $form['users_id']),
            'groups_id'             => max(0, $form['groups_id']),
            'avisar_whatsapp'       => $form['avisar_whatsapp'],
            'abrir_chamado'         => $form['abrir_chamado'],
        ];

        return [$dados, $erros, $form];
    }

    /** Defesa no próprio item: formato, duplicidade e situação inicial, venha de onde vier. */
    public function prepareInputForAdd($input)
    {
        $input['codigo'] = self::normalizarCodigo((string) ($input['codigo'] ?? ''));
        if (!Situacao::codigoValido($input['codigo'])) {
            Session::addMessageAfterRedirect(htmlescape("Código {$input['codigo']} com formato inválido."), false, ERROR);
            return false;
        }
        if (self::codigoExiste($input['codigo'])) {
            Session::addMessageAfterRedirect(htmlescape("O objeto {$input['codigo']} já está cadastrado."), false, ERROR);
            return false;
        }
        $input['situacao'] = 'nao_consultado';

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // O código identifica o objeto nos Correios: não muda depois de cadastrado.
        unset($input['codigo']);

        return $input;
    }

    /** Ao excluir de vez: leva junto eventos, alertas e leituras de alerta. */
    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id      = (int) $this->getID();
        $alertas = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_postalplus_alertas', 'WHERE' => ['plugin_postalplus_objetos_id' => $id]]) as $a) {
            $alertas[] = (int) $a['id'];
        }
        if ($alertas !== []) {
            $DB->delete('glpi_plugin_postalplus_alertaleituras', ['plugin_postalplus_alertas_id' => $alertas]);
        }
        $DB->delete('glpi_plugin_postalplus_alertas', ['plugin_postalplus_objetos_id' => $id]);
        $DB->delete('glpi_plugin_postalplus_eventos', ['plugin_postalplus_objetos_id' => $id]);
    }

    /**
     * Objetos visíveis nas entidades ativas, mais recentes primeiro.
     *
     * @return list<array<string,mixed>> linhas cruas da tabela
     */
    public static function listarVisiveis(int $limite = 500): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $t      = self::getTable();
        $linhas = [];
        foreach ($DB->request([
            'FROM'  => $t,
            'WHERE' => ["$t.is_deleted" => 0] + getEntitiesRestrictCriteria($t, '', '', true),
            'ORDER' => ["$t.date_creation DESC", "$t.id DESC"],
            'LIMIT' => $limite,
        ]) as $r) {
            $linhas[] = $r;
        }

        return $linhas;
    }

    /** Encerrados (entregues/devolvidos) somem do painel depois deste número de dias. */
    public const DIAS_ENCERRADOS_PAINEL = 30;

    /** Data limite: encerrados com último evento antes dela são "antigos". */
    public static function limiteEncerrados(string $agora): string
    {
        return date('Y-m-d H:i:s', strtotime($agora) - self::DIAS_ENCERRADOS_PAINEL * 86400);
    }

    /**
     * Encerrado há mais de DIAS_ENCERRADOS_PAINEL dias: acompanhamento terminado (is_active = 0 ou situação
     * final) e último evento — ou, sem evento, a última alteração — anterior ao limite.
     *
     * @param array<string,mixed> $r
     */
    public static function encerradoAntigo(array $r, string $agora): bool
    {
        $encerrado = (int) ($r['is_active'] ?? 1) === 0 || in_array((string) $r['situacao'], Situacao::FINAIS, true);
        if (!$encerrado) {
            return false;
        }
        $ref = (string) ($r['ultimo_evento_data'] ?: ($r['date_mod'] ?? ''));

        return $ref !== '' && $ref < self::limiteEncerrados($agora);
    }

    /**
     * Objetos do painel nas entidades ativas, mais recentes primeiro: em acompanhamento + encerrados nos
     * últimos DIAS_ENCERRADOS_PAINEL dias. Com $comAntigos, também os encerrados há mais tempo.
     *
     * @return list<array<string,mixed>> linhas cruas da tabela
     */
    public static function listarPainel(bool $comAntigos, string $agora, int $limite = 1000): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $t     = self::getTable();
        $where = [["$t.is_deleted" => 0], getEntitiesRestrictCriteria($t, '', '', true)];
        if (!$comAntigos) {
            $where[] = self::criterioRecente($t, $agora);
        }

        $linhas = [];
        foreach ($DB->request([
            'FROM'  => $t,
            'WHERE' => ['AND' => array_values(array_filter($where))],
            'ORDER' => ["$t.date_creation DESC", "$t.id DESC"],
            'LIMIT' => $limite,
        ]) as $r) {
            $linhas[] = $r;
        }

        return $linhas;
    }

    /** Quantos objetos visíveis estão encerrados há mais de DIAS_ENCERRADOS_PAINEL dias. */
    public static function contarEncerradosAntigos(string $agora): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $t      = self::getTable();
        $limite = self::limiteEncerrados($agora);
        $linha  = $DB->request([
            'COUNT' => 'total',
            'FROM'  => $t,
            'WHERE' => ['AND' => array_values(array_filter([
                ["$t.is_deleted" => 0],
                getEntitiesRestrictCriteria($t, '', '', true),
                ['OR' => [["$t.is_active" => 0], ["$t.situacao" => Situacao::FINAIS]]],
                ['OR' => [
                    ["$t.ultimo_evento_data" => ['<', $limite]],
                    ['AND' => [["$t.ultimo_evento_data" => null], ["$t.date_mod" => ['<', $limite]]]],
                ]],
            ]))],
        ])->current();

        return (int) ($linha['total'] ?? 0);
    }

    /** Quantos objetos (não excluídos) o usuário enxerga nas entidades ativas, encerrados inclusive. */
    public static function contarVisiveis(): int
    {
        $t = self::getTable();

        return countElementsInTable($t, ["$t.is_deleted" => 0] + getEntitiesRestrictCriteria($t, '', '', true));
    }

    /**
     * Critério "não é encerrado antigo": em acompanhamento, ou último evento (sem evento: última alteração)
     * dentro do limite.
     *
     * @return array<string,mixed>
     */
    private static function criterioRecente(string $t, string $agora): array
    {
        $limite = self::limiteEncerrados($agora);

        return ['OR' => [
            ['AND' => [["$t.is_active" => 1], ['NOT' => ["$t.situacao" => Situacao::FINAIS]]]],
            ["$t.ultimo_evento_data" => ['>=', $limite]],
            ['AND' => [["$t.ultimo_evento_data" => null], ["$t.date_mod" => ['>=', $limite]]]],
        ]];
    }

    /** Objeto pelo código, só se o usuário enxergar a entidade dele. */
    public static function buscarVisivel(string $codigo): ?self
    {
        $o = new self();
        if (!$o->getFromDBByCrit(['codigo' => $codigo, 'is_deleted' => 0]) || !$o->canViewItem()) {
            return null;
        }

        return $o;
    }

    /**
     * Eventos gravados, mais recente primeiro, no formato da linha do tempo do Detalhe.
     *
     * @return list<array<string,mixed>>
     */
    public static function eventosParaTela(int $id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $lista = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_postalplus_eventos',
            'WHERE' => ['plugin_postalplus_objetos_id' => $id],
            'ORDER' => ['data_evento DESC', 'id DESC'],
        ]) as $e) {
            $c  = Situacao::classificarEvento((string) $e['codigo'], (string) $e['tipo'], (string) $e['descricao']);
            $ts = $e['data_evento'] ? strtotime((string) $e['data_evento']) : 0;
            $item = [
                'data'   => $ts ? date('d/m', $ts) : '',
                'hora'   => $ts ? date('H:i', $ts) : '',
                'titulo' => (string) $e['descricao'],
                'local'  => Rastreio::localDoEvento($e),
                'cor'    => Situacao::corDoEvento($c['situacao'], $c['rotulo']),
                'atual'  => $lista === [],
            ];
            if (!empty($e['detalhe'])) {
                $item['nota'] = (string) $e['detalhe'];
            }
            $lista[] = $item;
        }

        return $lista;
    }

    /**
     * Objetos visíveis que ainda faz sentido consultar (ativos e não finalizados).
     *
     * @return list<array<string,mixed>>
     */
    public static function listarParaConsulta(int $limite): array
    {
        return array_slice(array_values(array_filter(
            self::listarVisiveis(),
            static fn($r) => (int) $r['is_active'] === 1 && !in_array($r['situacao'], Situacao::FINAIS, true)
        )), 0, $limite);
    }

    /**
     * Objetos com consulta devida para a ação automática: ativos, não finalizados, proxima_consulta nula ou
     * vencida. Todas as entidades (a ação roda sem sessão), mais atrasados primeiro (nulos antes).
     *
     * @return list<array<string,mixed>>
     */
    public static function listarDevidos(int $limite, string $agora): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $t      = self::getTable();
        $linhas = [];
        foreach ($DB->request([
            'FROM'  => $t,
            'WHERE' => [
                "$t.is_deleted" => 0,
                "$t.is_active"  => 1,
                'NOT'           => ["$t.situacao" => Situacao::FINAIS],
                'OR'            => [
                    ["$t.proxima_consulta" => null],
                    ["$t.proxima_consulta" => ['<=', $agora]],
                ],
            ],
            'ORDER' => ["$t.proxima_consulta ASC", "$t.id ASC"],
            'LIMIT' => max(1, $limite),
        ]) as $r) {
            $linhas[] = $r;
        }

        return $linhas;
    }

    /**
     * Linha da tabela no mesmo formato dos dados de demonstração (painel e detalhe usam o mesmo template).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function paraTela(array $r): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $data = static function (?string $v, string $fmt): string {
            return $v ? date($fmt, strtotime($v)) : '';
        };

        $chamadoTitulo = '';
        if ((int) $r['tickets_id'] > 0) {
            $t = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int) $r['tickets_id']]])->current();
            $chamadoTitulo = (string) ($t['name'] ?? '');
        }

        $previsto = $data($r['prazo_previsto'] ?? null, 'd/m/Y');
        $situacao = (string) $r['situacao'];
        $erro     = (string) ($r['erro_consulta'] ?? '');
        $hoje     = date('Y-m-d', strtotime($_SESSION['glpi_currenttime'] ?? 'now'));

        // Rótulo da pílula: mais específico que a situação quando há evento (ex.: "Carteiro não atendido").
        if (!empty($r['ultimo_evento_descricao'])) {
            $rotulo = Situacao::classificarEvento((string) $r['ultimo_evento_codigo'], (string) $r['ultimo_evento_tipo'], (string) $r['ultimo_evento_descricao'])['rotulo'];
        } elseif ($erro !== '') {
            $rotulo = str_contains($erro, 'SRO-020') ? 'Não encontrado' : 'Erro na consulta';
        } else {
            $rotulo = Situacao::ROTULOS[$situacao] ?? $situacao;
        }

        // Prazo / alerta (as regras completas entram no Bloco 7).
        $alerta = '—';
        $nivel  = '';
        $prazo  = null;
        if ($situacao === 'aguardando_retirada' && !empty($r['prazo_retirada'])) {
            $ate       = (string) $r['prazo_retirada'];
            $total     = Situacao::PRAZO_RETIRADA_DIAS;
            $restantes = (int) floor((strtotime($ate) - strtotime($hoje)) / 86400);
            $chegou    = date('Y-m-d', strtotime("$ate -$total days"));
            $prazo     = [
                'restantes'  => max(0, $restantes),
                'chegou'     => date('d/m', strtotime($chegou)),
                'ate'        => date('d/m/Y', strtotime($ate)),
                'decorridos' => min($total, max(0, $total - $restantes)),
                'total'      => $total,
            ];
            $alerta = 'Retirar até ' . date('d/m', strtotime($ate)) . ($restantes >= 0 ? " · faltam $restantes dia(s)" : ' · prazo esgotado');
            $nivel  = $restantes <= 2 ? ($restantes < 0 ? 'critico' : 'atencao') : '';
        } elseif ($situacao === 'entregue') {
            $alerta = 'Entregue' . (!empty($r['ultimo_evento_data']) ? ' em ' . date('d/m', strtotime((string) $r['ultimo_evento_data'])) : '');
        } elseif ($rotulo === 'Devolvido ao remetente') {
            $alerta = 'Devolvido' . (!empty($r['ultimo_evento_data']) ? ' em ' . date('d/m', strtotime((string) $r['ultimo_evento_data'])) : '');
        } elseif ($erro !== '' && empty($r['ultimo_evento_descricao'])) {
            $alerta = str_contains($erro, 'SRO-020') ? 'Sem registro nos Correios' : mb_strimwidth($erro, 0, 60, '…');
            $nivel  = 'atencao';
        } elseif ($previsto !== '') {
            $alerta = 'Previsto ' . substr($previsto, 0, 5);
        }

        return [
            'id'             => (int) $r['id'],
            'demo'           => false,
            'codigo'         => (string) $r['codigo'],
            'servico'        => (string) ($r['servico'] ?: 'Serviço a confirmar'),
            'situacao'       => $situacao,
            'rotulo'         => $rotulo,
            'evento'         => (string) ($r['ultimo_evento_descricao'] ?: (empty($r['ultima_consulta']) ? 'Aguardando a primeira consulta à API' : 'Nenhum evento devolvido pelos Correios')),
            'local'          => (string) ($r['ultimo_evento_local'] ?? ''),
            'atualizado'     => $data($r['ultimo_evento_data'] ?? null, 'd/m H:i') ?: '—',
            'alerta'         => $alerta,
            'alerta_nivel'   => $nivel,
            'destinatario'   => (string) ($r['destinatario_nome'] ?: '—'),
            'contato'        => (string) ($r['destinatario_contato'] ?? ''),
            'whatsapp'       => self::formatarWhatsapp($r['destinatario_whatsapp'] ?? ''),
            'email'          => (string) ($r['destinatario_email'] ?? ''),
            'cidade'         => (string) ($r['destinatario_cidade'] ?? ''),
            'retirada'       => $situacao === 'aguardando_retirada' ? (string) ($r['ultimo_evento_local'] ?? '') : '',
            'chamado'        => (int) $r['tickets_id'],
            'chamado_titulo' => $chamadoTitulo,
            'postagem'       => $data($r['data_postagem'] ?? null, 'd/m/Y') ?: 'data não informada',
            'postagem_local' => '',
            'previsto'       => $previsto ?: '—',
            'prazo_retirada' => $prazo,
            'eventos'        => [],
            'ultima_consulta'=> $data($r['ultima_consulta'] ?? null, 'd/m/Y H:i'),
            'erro_consulta'  => $erro,
            'consultado'     => !empty($r['ultima_consulta']),
            'proxima_consulta' => (int) ($r['is_active'] ?? 1) === 1 ? $data($r['proxima_consulta'] ?? null, 'd/m/Y H:i') : '',
            'encerrado'      => (int) ($r['is_active'] ?? 1) === 0,
            'alertas'        => [],
            'entidade'       => (string) Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']),
            'responsavel'    => (int) $r['users_id'] > 0 ? (string) Dropdown::getDropdownName('glpi_users', (int) $r['users_id']) : '—',
            'grupo'          => (int) $r['groups_id'] > 0 ? (string) Dropdown::getDropdownName('glpi_groups', (int) $r['groups_id']) : '',
            'avisar_whatsapp'=> (int) $r['avisar_whatsapp'] === 1,
            'abrir_chamado'  => (int) $r['abrir_chamado'] === 1,
            'cadastrado'     => $data($r['date_creation'] ?? null, 'd/m/Y H:i'),
        ];
    }
}
