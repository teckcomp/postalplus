<?php

/**
 * Postal+ — e-mail da equipe pela fila de notificações do GLPI (Bloco 7).
 *
 * Não envia direto: insere em glpi_queuednotifications (modo "mailing") e a ação automática
 * queuednotification do GLPI envia com o SMTP configurado em Configurar › Notificações. Assim o
 * plugin não trava a consulta esperando o servidor de e-mail.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use Config;
use Notification_NotificationTemplate;
use QueuedNotification;

class Notificacao
{
    public const EVENTO = 'postalplus_alerta';

    /** @return list<string> e-mails válidos da configuração "E-mail da equipe" */
    public static function destinatarios(array $cfg): array
    {
        $lista = [];
        foreach (preg_split('/[,;\s]+/', (string) ($cfg['email_equipe'] ?? '')) ?: [] as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $lista[$e] = $e;
            }
        }

        return array_values($lista);
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $r linha do objeto
     * @return array{ok:bool, mensagem:string}
     */
    public static function emailEquipe(array $cfg, array $r, string $assunto, string $texto, string $nivel = 'info'): array
    {
        $para = self::destinatarios($cfg);
        if ($para === []) {
            return ['ok' => false, 'mensagem' => 'e-mail da equipe não configurado'];
        }

        $corpo = $texto . "\n\n" . Chamados::resumoObjeto($r) . "\n\n— Postal+ · nível: $nivel";

        return self::enfileirar($para, $assunto, $corpo, (int) ($r['entities_id'] ?? 0), (int) ($r['id'] ?? 0));
    }

    /**
     * "Enviar notificação de teste" na Configuração.
     *
     * @return array{ok:bool, mensagem:string}
     */
    public static function teste(array $cfg): array
    {
        $para = self::destinatarios($cfg);
        if ($para === []) {
            return ['ok' => false, 'mensagem' => 'Preencha e salve o E-mail da equipe antes de testar.'];
        }
        $r = self::enfileirar($para, '[Postal+] Notificação de teste', "Este é um e-mail de teste do Postal+ enviado em " . date('d/m/Y H:i') . ".\nSe chegou, a fila de notificações do GLPI está funcionando para " . implode(', ', $para) . '.', 0, 0);
        if ($r['ok']) {
            $r['mensagem'] = 'E-mail de teste colocado na fila do GLPI para ' . implode(', ', $para) . '. O envio depende da ação automática queuednotification e do SMTP em Configurar › Notificações.';
        }

        return $r;
    }

    /**
     * @param list<string> $para
     * @return array{ok:bool, mensagem:string}
     */
    private static function enfileirar(array $para, string $assunto, string $corpo, int $entidade, int $objetoId): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (empty($CFG_GLPI['use_notifications']) || empty($CFG_GLPI['notifications_mailing'])) {
            return ['ok' => false, 'mensagem' => 'notificações por e-mail desligadas no GLPI (Configurar › Notificações)'];
        }

        $remetente = Config::getAdminEmailSender($entidade > 0 ? $entidade : null);
        $agora     = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $ok        = 0;
        foreach ($para as $email) {
            $q  = new QueuedNotification();
            $id = $q->add([
                'itemtype'                 => Objeto::class,
                'items_id'                 => $objetoId,
                'notificationtemplates_id' => 0,
                'entities_id'              => $entidade,
                'sent_try'                 => 0,
                'create_time'              => $agora,
                'send_time'                => $agora,
                'name'                     => mb_substr($assunto, 0, 255),
                'sender'                   => (string) ($remetente['email'] ?? ''),
                'sendername'               => (string) ($remetente['name'] ?? 'Postal+'),
                'recipient'                => $email,
                'recipientname'            => $email,
                'body_text'                => $corpo,
                'body_html'                => '',
                'messageid'                => 'postalplus-' . $objetoId . '-' . uniqid('', true),
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'event'                    => self::EVENTO,
                'attach_documents'         => 0,
            ]);
            if ($id) {
                $ok++;
            }
        }
        if ($ok === 0) {
            return ['ok' => false, 'mensagem' => 'não foi possível colocar o e-mail na fila do GLPI'];
        }

        return ['ok' => true, 'mensagem' => "e-mail na fila do GLPI para $ok destinatário(s)"];
    }

    /** URL absoluta do Detalhe do objeto (para e-mail e chamado). */
    public static function urlObjeto(string $codigo): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        if ($base === '') {
            $base = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        }

        return $base . PLUGIN_POSTALPLUS_WEBPATH . '/front/objeto.php?codigo=' . rawurlencode($codigo);
    }
}
