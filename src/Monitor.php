<?php

/**
 * Postal+ — ação automática PostalplusConsulta (Bloco 4) e estado do monitoramento.
 *
 * O GLPI chama cronPostalplusConsulta() a cada FREQUENCIA_CRON segundos (modo CLI). Cada execução:
 * - só trabalha dentro da janela janela_inicio–janela_fim da Configuração;
 * - pega até LIMITE_CRON objetos ativos, não finalizados, com proxima_consulta vencida (ou nula),
 *   em qualquer entidade (a ação roda sem sessão de usuário);
 * - chama Rastreio::consultar($linhas, 'cron'), que grava eventos, situação e a próxima consulta
 *   conforme a frequência da situação (freq_*). Entregue/devolvido encerra o acompanhamento.
 * Sem objeto devido, ou fora da janela, não grava linha em "Últimas execuções" (só no log da ação).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use CommonGLPI;
use CronTask;

class Monitor extends CommonGLPI
{
    /** Nome da ação automática (método cronPostalplusConsulta). */
    public const CRON = 'PostalplusConsulta';

    /** De quanto em quanto tempo o GLPI chama a ação (segundos). Quem decide quem é consultado é proxima_consulta. */
    public const FREQUENCIA_CRON = 300;

    /** Objetos por execução (parâmetro da ação; ajustável em Configurar › Ações automáticas). */
    public const LIMITE_CRON = 100;

    /** Sem execução há mais que isto (dentro da janela) = ação parada (cron do sistema fora do ar?). */
    private const PARADA_APOS = 1200;

    public static function getTypeName($nb = 0)
    {
        return 'Postal+ · monitoramento';
    }

    /**
     * @return array{description:string, parameter:string}
     */
    public static function cronInfo($name)
    {
        return [
            'description' => 'Postal+ — consulta automática do rastreio na API Rastro dos Correios',
            'parameter'   => 'Objetos por execução',
        ];
    }

    /**
     * @return int 1 = trabalhou, 0 = nada a fazer, -1 = interrompida (credencial/token/rede)
     */
    public static function cronPostalplusConsulta(CronTask $task)
    {
        $cfg   = Configuracao::lerCru();
        $agora = time();

        if (!self::dentroDaJanela((string) $cfg['janela_inicio'], (string) $cfg['janela_fim'], $agora)) {
            $task->log(sprintf('Fora da janela de consulta (%s às %s).', $cfg['janela_inicio'], $cfg['janela_fim']));
            return 0;
        }
        if ((string) $cfg['cws_usuario'] === '' || (string) $cfg['cws_codigo_acesso'] === '' || (string) $cfg['cws_cartao'] === '') {
            $task->log('Credenciais CWS não configuradas: nada consultado.');
            return 0;
        }

        $limite = (int) ($task->fields['param'] ?? 0);
        $linhas = Objeto::listarDevidos($limite > 0 ? $limite : self::LIMITE_CRON, date('Y-m-d H:i:s', $agora));
        if ($linhas === []) {
            $task->log('Nenhum objeto com consulta devida.');
            return 0;
        }

        $r = Rastreio::doGlpi()->consultar($linhas, 'cron');
        $task->addVolume($r['objetos']);
        $task->log($r['mensagem']);

        return $r['interrompida'] ? -1 : 1;
    }

    /** Janela "HH:MM"–"HH:MM" (fim exclusivo). Início > fim = atravessa a meia-noite; iguais = o dia todo. */
    public static function dentroDaJanela(string $inicio, string $fim, int $ts): bool
    {
        $ini = self::minutos($inicio, 0);
        $end = self::minutos($fim, 1440);
        $m   = (int) date('G', $ts) * 60 + (int) date('i', $ts);

        if ($ini === $end || ($ini === 0 && $end >= 1440)) {
            return true;
        }

        return $ini < $end ? ($m >= $ini && $m < $end) : ($m >= $ini || $m < $end);
    }

    /** Primeiro instante >= $ts que cai dentro da janela. */
    public static function ajustarParaJanela(int $ts, string $inicio, string $fim): int
    {
        if (self::dentroDaJanela($inicio, $fim, $ts)) {
            return $ts;
        }
        $ini  = self::minutos($inicio, 0);
        $hoje = strtotime(date('Y-m-d', $ts) . ' 00:00:00') + $ini * 60;

        return $hoje > $ts ? $hoje : strtotime('+1 day', $hoje);
    }

    private static function minutos(string $hhmm, int $padrao): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmm), $m)) {
            return $padrao;
        }

        return min(1440, (int) $m[1] * 60 + (int) $m[2]);
    }

    /** Linha da ação automática em glpi_crontasks (ou null se não registrada). */
    public static function tarefa(): ?array
    {
        $t = new CronTask();

        return $t->getFromDBbyName(self::class, self::CRON) ? $t->fields : null;
    }

    /**
     * Selo do painel e card da Configuração.
     *
     * @return array{nivel:string, texto:string, titulo:string, ultima:string, proxima:string, tarefa_id:int, ativa:bool}
     */
    public static function estado(?int $agora = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $agora ??= time();
        $cfg     = Configuracao::lerCru();
        $tarefa  = self::tarefa();
        $base    = ['ultima' => '', 'proxima' => '', 'tarefa_id' => (int) ($tarefa['id'] ?? 0), 'ativa' => false];

        if ($tarefa === null) {
            return ['nivel' => 'erro', 'texto' => 'Ação automática não registrada',
                'titulo' => 'Reinstale o plugin (plugin:install --force) para registrar a ação ' . self::CRON . '.'] + $base;
        }
        if ((int) $tarefa['state'] === CronTask::STATE_DISABLE) {
            return ['nivel' => 'parado', 'texto' => 'Monitoramento desativado',
                'titulo' => 'A ação automática ' . self::CRON . ' está desativada em Configurar › Ações automáticas.'] + $base;
        }
        $base['ativa'] = true;

        $ult = $DB->request(['SELECT' => ['date_start'], 'FROM' => 'glpi_plugin_postalplus_consultas', 'ORDER' => 'date_start DESC', 'LIMIT' => 1])->current();
        $base['ultima'] = !empty($ult['date_start']) ? self::hora(strtotime((string) $ult['date_start']), $agora) : '';

        $lastrun  = !empty($tarefa['lastrun']) ? strtotime((string) $tarefa['lastrun']) : 0;
        $janelaOk = self::dentroDaJanela((string) $cfg['janela_inicio'], (string) $cfg['janela_fim'], $agora);
        $modo     = (int) $tarefa['mode'] === CronTask::MODE_EXTERNAL ? 'CLI' : 'GLPI';

        if ($janelaOk && ($lastrun === 0 || $agora - $lastrun > max(self::PARADA_APOS, 3 * (int) $tarefa['frequency']))) {
            $texto = $lastrun === 0 ? 'Ação automática ainda não executou' : 'Ação automática parada desde ' . self::hora($lastrun, $agora);
            $dica  = $modo === 'CLI'
                ? 'Modo CLI: depende do cron do sistema chamando front/cron.php a cada minuto.'
                : 'Modo GLPI: só executa quando alguém navega no GLPI.';
            return ['nivel' => 'atencao', 'texto' => $texto, 'titulo' => $dica] + $base;
        }

        $min = $DB->request([
            'SELECT' => ['proxima_consulta'],
            'FROM'   => Objeto::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'is_active' => 1, 'NOT' => ['situacao' => Situacao::FINAIS]],
            'ORDER'  => ['proxima_consulta ASC'],
            'LIMIT'  => 1,
        ])->current();

        if ($min === null) {
            return ['nivel' => 'ok', 'texto' => 'Monitorando · nenhum objeto em acompanhamento',
                'titulo' => "Ação automática " . self::CRON . " ativa (modo $modo)."] + $base;
        }

        // Próxima consulta efetiva: o objeto mais atrasado, mas não antes do próximo ciclo da ação, e dentro da janela.
        $prox  = !empty($min['proxima_consulta']) ? strtotime((string) $min['proxima_consulta']) : $agora;
        $ciclo = $lastrun > 0 ? $lastrun + (int) $tarefa['frequency'] : $agora;
        $prox  = max($prox, $ciclo, $agora);
        $prox  = self::ajustarParaJanela($prox, (string) $cfg['janela_inicio'], (string) $cfg['janela_fim']);
        $base['proxima'] = $prox - $agora < 60 ? 'em instantes' : self::hora($prox, $agora);

        $texto = 'Monitorando'
            . ($base['ultima'] !== '' ? ' · última consulta ' . $base['ultima'] : '')
            . ' · próxima ' . $base['proxima'];

        return ['nivel' => 'ok', 'texto' => $texto,
            'titulo' => sprintf('Ação automática %s ativa (modo %s) · janela %s às %s', self::CRON, $modo, $cfg['janela_inicio'], $cfg['janela_fim'])] + $base;
    }

    /** "09:42" se for hoje, senão "06/10 06:00". */
    private static function hora(int $ts, int $agora): string
    {
        return date('Y-m-d', $ts) === date('Y-m-d', $agora) ? date('H:i', $ts) : date('d/m H:i', $ts);
    }
}
