<?php

/**
 * Postal+ — transporte HTTP do cliente CWS (permite trocar por simulador em desenvolvimento).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus\Cws;

interface Transporte
{
    /**
     * @param array<string,string> $cabecalhos
     * @return array{status:int, corpo:string, erro:?string}  status 0 = falha de rede (ver erro)
     */
    public function requisitar(string $metodo, string $url, array $cabecalhos, ?string $corpo = null): array;
}
