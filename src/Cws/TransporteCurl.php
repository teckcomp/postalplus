<?php

/**
 * Postal+ — transporte HTTP real via cURL.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus\Cws;

class TransporteCurl implements Transporte
{
    public function __construct(private int $timeout = 20) {}

    public function requisitar(string $metodo, string $url, array $cabecalhos, ?string $corpo = null): array
    {
        $ch = curl_init($url);
        $linhas = [];
        foreach ($cabecalhos as $k => $v) {
            $linhas[] = "$k: $v";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $linhas,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($corpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
        }

        $resposta = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro     = $resposta === false ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $resposta === false ? 0 : $status,
            'corpo'  => $resposta === false ? '' : (string) $resposta,
            'erro'   => $erro,
        ];
    }
}
