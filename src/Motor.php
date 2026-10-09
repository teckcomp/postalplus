<?php

/**
 * Postal+ — motor de regras (Bloco 7/8).
 *
 * Roda depois de cada consulta à API (manual, cadastro ou automática), objeto por objeto, com a linha
 * já atualizada pelo Rastreio. Avalia as 5 regras da tela "Regras de alerta" e grava cada alerta em
 * glpi_plugin_postalplus_alertas — uma vez só, pela coluna `chave` (ex.: "critico:<hash do evento>",
 * "retirada:lembrete:<prazo>"). Depois age pelos canais marcados na regra:
 *   tela     → o alerta fica disponível para o toast (ajax/alertas.php, leitura em alertaleituras);
 *   email    → e-mail da equipe pela fila de notificações do GLPI (Notificacao);
 *   chamado  → acompanhamento no chamado vinculado ou chamado novo (Chamados); na regra
 *              "Entrega confirmada" significa solucionar o chamado vinculado;
 *   whatsapp → ainda não age (backlog: webhook do n8n).
 *
 * "Atrasado" e "sem movimentação" não vêm da API: são calculados aqui e gravados em objetos.situacao.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

class Motor
{
    public const TABELA = 'glpi_plugin_postalplus_alertas';

    /** Situações derivadas (calculadas aqui, não pela API). */
    public const DERIVADAS = ['atrasado', 'sem_movimentacao'];

    /** Situações em que atraso e sem movimentação não se aplicam. */
    private const SEM_DERIVACAO = ['aguardando_retirada', 'entregue', 'problema'];

    /** @var array<string,array<string,mixed>>|null regras por chave */
    private ?array $regras = null;

    /** @var array<string,mixed> */
    private array $cfg;

    private string $agora;

    /** @var array<string,mixed> linha do objeto em avaliação (tickets_id atualizado quando um chamado é aberto) */
    private array $linha = [];

    public function __construct(?array $cfg = null, ?string $agora = null)
    {
        $this->cfg   = $cfg ?? Configuracao::lerCru();
        $this->agora = $agora ?? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }

    /**
     * Avalia uma lista de objetos (ids) depois de uma consulta.
     *
     * @param array<int,list<string>> $novosPorObjeto id => hashes dos eventos gravados nesta consulta
     * @return int alertas gerados
     */
    public function avaliarLote(array $novosPorObjeto): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $total = 0;
        foreach ($novosPorObjeto as $id => $hashes) {
            $r = $DB->request(['FROM' => Objeto::getTable(), 'WHERE' => ['id' => (int) $id]])->current();
            if ($r === null || (int) $r['is_deleted'] === 1) {
                continue;
            }
            try {
                $total += $this->avaliarObjeto($r, (array) $hashes);
            } catch (\Throwable $e) {
                Configuracao::log('motor: exceção em ' . $r['codigo'] . ': ' . $e::class . ' ' . $e->getMessage());
            }
        }

        return $total;
    }

    /**
     * Avalia as regras para um objeto. Devolve quantos alertas novos gerou.
     *
     * @param array<string,mixed> $r      linha de glpi_plugin_postalplus_objetos (já atualizada)
     * @param list<string>        $novos  hashes dos eventos gravados nesta consulta
     */
    public function avaliarObjeto(array $r, array $novos): int
    {
        $regras      = $this->regras();
        $n           = 0;
        $hoje        = substr($this->agora, 0, 10);
        $this->linha = $r;

        // ---- Entrega confirmada (encerramento) ----
        if ((string) $r['situacao'] === 'entregue') {
            $regra = $regras['entrega_confirmada'] ?? null;
            if ($regra && $regra['is_active']) {
                $quando = !empty($r['ultimo_evento_data']) ? date('d/m H:i', strtotime((string) $r['ultimo_evento_data'])) : '';
                $n += $this->disparar($r, $regra, 'entrega:' . ($r['ultimo_evento_data'] ?? $hoje), 'info', 'Entrega confirmada',
                    trim("Objeto {$r['codigo']} entregue" . ($quando !== '' ? " em $quando" : '') . (!empty($r['ultimo_evento_local']) ? ' · ' . $r['ultimo_evento_local'] : '') . '.'));
            }
            return $n;
        }

        // ---- Eventos críticos: só o evento MAIS RECENTE do objeto, e só se chegou nesta consulta
        //      (histórico antigo de um objeto recém-cadastrado não vira alerta). ----
        $regra = $regras['eventos_criticos'] ?? null;
        if ($regra && $regra['is_active'] && $novos !== []) {
            foreach ($this->ultimoEventoSeNovo((int) $r['id'], $novos) as $ev) {
                $class = Situacao::classificarEvento((string) $ev['codigo'], (string) $ev['tipo'], (string) $ev['descricao']);
                if ($class['critico'] === null || !in_array($class['critico'], $regra['eventos'], true)) {
                    continue;
                }
                $quando = !empty($ev['data_evento']) ? date('d/m H:i', strtotime((string) $ev['data_evento'])) : '';
                $n += $this->disparar($r, $regra, 'critico:' . $ev['hash'], 'critico', $class['rotulo'],
                    trim($ev['descricao'] . ($quando !== '' ? " · $quando" : '') . ' · ' . Rastreio::localDoEvento($ev), ' ·') . '.');
            }
        }

        // Devolvido ao remetente encerra também: nada de atraso/sem movimentação.
        if ((int) ($r['is_active'] ?? 1) === 0) {
            return $n;
        }

        // ---- Aguardando retirada ----
        $regra = $regras['aguardando_retirada'] ?? null;
        if ($regra && $regra['is_active'] && (string) $r['situacao'] === 'aguardando_retirada' && !empty($r['prazo_retirada'])) {
            $prazo     = (string) $r['prazo_retirada'];
            $restantes = (int) floor((strtotime($prazo) - strtotime($hoje)) / 86400);
            $local     = (string) ($r['ultimo_evento_local'] ?? '');
            $n += $this->disparar($r, $regra, "retirada:chegou:$prazo", 'info', 'Aguardando retirada na agência',
                "Objeto {$r['codigo']} aguarda retirada" . ($local !== '' ? " em $local" : '') . ' até ' . date('d/m/Y', strtotime($prazo)) . '.');
            if ($restantes <= (int) $regra['parametro']) {
                $txt = $restantes < 0 ? 'prazo de retirada esgotado' : ($restantes === 0 ? 'último dia para retirada' : "faltam $restantes dia(s) para a devolução ao remetente");
                $n += $this->disparar($r, $regra, "retirada:lembrete:$prazo", $restantes <= 0 ? 'critico' : 'atencao', 'Prazo de retirada acabando',
                    "Objeto {$r['codigo']}: $txt (retirar até " . date('d/m/Y', strtotime($prazo)) . ($local !== '' ? " em $local" : '') . ').');
            }
        }

        // ---- Situações derivadas: sem movimentação (prioridade) e atraso ----
        $derivada = null;
        $base     = in_array((string) $r['situacao'], self::DERIVADAS, true) ? self::situacaoBase($r) : (string) $r['situacao'];

        $regra = $regras['sem_movimentacao'] ?? null;
        if ($regra && $regra['is_active'] && !in_array($base, self::SEM_DERIVACAO, true) && !empty($r['ultimo_evento_data'])) {
            $uteis = self::diasUteis(substr((string) $r['ultimo_evento_data'], 0, 10), $hoje);
            if ($uteis >= (int) $regra['parametro']) {
                $derivada = 'sem_movimentacao';
                $n += $this->disparar($r, $regra, 'semmov:' . $r['ultimo_evento_data'], 'critico', 'Sem movimentação',
                    "Objeto {$r['codigo']} sem novo evento há $uteis dia(s) útil(eis) (último: " . date('d/m H:i', strtotime((string) $r['ultimo_evento_data'])) . ', ' . $r['ultimo_evento_descricao'] . '). Possível extravio.');
            }
        }

        $regra = $regras['atraso'] ?? null;
        if ($regra && $regra['is_active'] && !in_array($base, self::SEM_DERIVACAO, true) && !empty($r['prazo_previsto'])) {
            $limite = date('Y-m-d', strtotime((string) $r['prazo_previsto'] . ' +' . (int) $regra['parametro'] . ' days'));
            if ($hoje > $limite) {
                $dias = (int) floor((strtotime($hoje) - strtotime((string) $r['prazo_previsto'])) / 86400);
                $derivada ??= 'atrasado';
                $n += $this->disparar($r, $regra, 'atraso:' . $r['prazo_previsto'], 'atencao', 'Atraso na entrega',
                    "Objeto {$r['codigo']} previsto para " . date('d/m/Y', strtotime((string) $r['prazo_previsto'])) . " e ainda não entregue ($dias dia(s) de atraso).");
            }
        }

        $final = $derivada ?? $base;
        if ($final !== (string) $r['situacao']) {
            /** @var \DBmysql $DB */
            global $DB;
            $DB->update(Objeto::getTable(), ['situacao' => $final], ['id' => (int) $r['id']]);
        }

        return $n;
    }

    /** Situação que o último evento daria, sem as derivadas. */
    public static function situacaoBase(array $r): string
    {
        if (empty($r['ultimo_evento_descricao'])) {
            return empty($r['ultima_consulta']) ? 'nao_consultado' : 'em_transito';
        }

        return Situacao::classificarEvento((string) $r['ultimo_evento_codigo'], (string) $r['ultimo_evento_tipo'], (string) $r['ultimo_evento_descricao'])['situacao'];
    }

    /** Dias úteis (seg–sex, sem feriados) entre duas datas Y-m-d, exclusivo no início. */
    public static function diasUteis(string $de, string $ate): int
    {
        $a = strtotime($de);
        $b = strtotime($ate);
        if (!$a || !$b || $b <= $a) {
            return 0;
        }
        $n = 0;
        for ($t = $a + 86400; $t <= $b; $t += 86400) {
            if ((int) date('N', $t) <= 5) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Grava o alerta (se a chave ainda não existe) e executa os canais. Devolve 1 se gerou, 0 se já existia.
     *
     * @param array<string,mixed> $r
     * @param array<string,mixed> $regra
     */
    private function disparar(array $r, array $regra, string $chave, string $nivel, string $titulo, string $mensagem): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Chamado aberto por um alerta anterior deste mesmo ciclo: os seguintes viram acompanhamento nele.
        if ((int) ($this->linha['id'] ?? 0) === (int) $r['id'] && (int) ($this->linha['tickets_id'] ?? 0) > 0) {
            $r['tickets_id'] = (int) $this->linha['tickets_id'];
        }

        $chave = mb_substr($chave, 0, 120);
        $ja    = $DB->request(['COUNT' => 'cpt', 'FROM' => self::TABELA, 'WHERE' => ['plugin_postalplus_objetos_id' => (int) $r['id'], 'chave' => $chave]])->current();
        if ((int) ($ja['cpt'] ?? 0) > 0) {
            return 0;
        }

        $canais    = [];
        $resultado = [];
        if ($regra['canal_tela']) {
            $canais[] = 'tela';
        }

        $alertaId = $DB->insert(self::TABELA, [
            'plugin_postalplus_objetos_id' => (int) $r['id'],
            'regra'                        => mb_substr((string) $regra['chave'], 0, 40),
            'chave'                        => $chave,
            'nivel'                        => $nivel,
            'titulo'                       => mb_substr($titulo, 0, 255),
            'mensagem'                     => $mensagem,
            'canais'                       => implode(',', $canais),
            'tickets_id'                   => 0,
            'date_creation'                => $this->agora,
        ]) ? (int) $DB->insertId() : 0;

        $ticketId = 0;
        if ($regra['canal_email']) {
            $res = Notificacao::emailEquipe($this->cfg, $r, "[Postal+] $titulo · {$r['codigo']}", $mensagem, $nivel);
            $resultado['email'] = $res['mensagem'];
            if ($res['ok']) {
                $canais[] = 'email';
            }
        }
        if ($regra['canal_chamado']) {
            $res = (string) $regra['chave'] === 'entrega_confirmada'
                ? Chamados::solucionar($r, $mensagem)
                : Chamados::registrar($this->cfg, $r, $titulo, $mensagem, $nivel);
            $resultado['chamado'] = $res['mensagem'];
            if ($res['ok']) {
                $canais[] = 'chamado';
                $ticketId = (int) $res['tickets_id'];
                if ($ticketId > 0 && (int) ($this->linha['id'] ?? 0) === (int) $r['id']) {
                    $this->linha['tickets_id'] = $ticketId;
                }
            }
        }
        if ($regra['canal_whatsapp']) {
            $resultado['whatsapp'] = 'não enviado: aviso por WhatsApp ainda não disponível (backlog).';
        }

        if ($alertaId > 0) {
            $DB->update(self::TABELA, [
                'canais'          => implode(',', $canais),
                'tickets_id'      => $ticketId,
                'resultado_envio' => $resultado !== [] ? json_encode($resultado, JSON_UNESCAPED_UNICODE) : null,
            ], ['id' => $alertaId]);
        }
        Configuracao::log("alerta [{$regra['chave']}] {$r['codigo']}: $titulo (" . implode(',', $canais) . ')');

        return 1;
    }

    /** Último evento do objeto, se o hash dele está entre os gravados agora. @return list<array<string,mixed>> */
    private function ultimoEventoSeNovo(int $objetoId, array $hashes): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ev = $DB->request([
            'FROM'  => 'glpi_plugin_postalplus_eventos',
            'WHERE' => ['plugin_postalplus_objetos_id' => $objetoId],
            'ORDER' => ['data_evento DESC', 'id DESC'],
            'LIMIT' => 1,
        ])->current();

        return $ev !== null && in_array((string) $ev['hash'], array_map('strval', $hashes), true) ? [$ev] : [];
    }

    /** @return array<string,array<string,mixed>> */
    private function regras(): array
    {
        if ($this->regras === null) {
            $this->regras = [];
            foreach (Regras::listar() as $regra) {
                $this->regras[$regra['chave']] = $regra;
            }
        }

        return $this->regras;
    }
}
