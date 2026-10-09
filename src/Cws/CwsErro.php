<?php

/**
 * Postal+ — erro do cliente CWS com mensagem já pronta para a tela (sem dados sensíveis).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus\Cws;

class CwsErro extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly string $tipo = 'erro', public readonly int $status = 0)
    {
        parent::__construct($mensagem);
    }
}
