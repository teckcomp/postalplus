<?php

/**
 * Postal+ — importação em lote (Bloco 10): códigos colados, arquivo CSV ou Relatório de Objetos (RO).
 *
 * Qualquer arquivo de texto serve: o parser acha os códigos de rastreio (2 letras + 9 dígitos + 2 letras)
 * em qualquer coluna e, se houver cabeçalho reconhecível, lê também destinatário, responsável pelo
 * recebimento, WhatsApp, e-mail, cidade e chamado. Cada objeto é gravado por Objeto::add (mesmas
 * defesas do cadastro individual) e consultado na API logo depois (até Rastreio::LIMITE_MANUAL).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use Session;

class Importacao
{
    public const MAXIMO = 500;

    /** Cabeçalhos reconhecidos (sem acento, minúsculas) => campo do objeto. */
    private const COLUNAS = [
        'destinatario'  => 'destinatario_nome',
        'cliente'       => 'destinatario_nome',
        'empresa'       => 'destinatario_nome',
        'nome'          => 'destinatario_nome',
        'contato'       => 'destinatario_contato',
        'responsavel'   => 'destinatario_contato',
        'recebedor'     => 'destinatario_contato',
        'whatsapp'      => 'destinatario_whatsapp',
        'telefone'      => 'destinatario_whatsapp',
        'celular'       => 'destinatario_whatsapp',
        'fone'          => 'destinatario_whatsapp',
        'email'         => 'destinatario_email',
        'e-mail'        => 'destinatario_email',
        'cidade'        => 'destinatario_cidade',
        'cidade/uf'     => 'destinatario_cidade',
        'destino'       => 'destinatario_cidade',
        'chamado'       => 'tickets_id',
        'ticket'        => 'tickets_id',
        'servico'       => 'servico',
        'postagem'      => 'data_postagem',
        'data postagem' => 'data_postagem',
        'data'          => 'data_postagem',
    ];

    /**
     * Extrai linhas {codigo, campos...} de um texto (colado ou conteúdo de arquivo).
     *
     * @return array{itens:list<array<string,mixed>>, invalidos:list<string>, colunas:list<string>}
     */
    public static function analisar(string $texto): array
    {
        $texto = self::utf8($texto);
        $linhas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
        $sep    = self::separador($texto);

        $mapa     = []; // índice da coluna => campo
        $colCod   = null;
        $itens    = [];
        $invalid  = [];
        $vistos   = [];
        $colunas  = [];

        foreach ($linhas as $num => $linha) {
            if (trim($linha) === '') {
                continue;
            }
            $cels = $sep === null ? preg_split('/[\s,;]+/', trim($linha)) : str_getcsv($linha, $sep, '"', '\\');
            $cels = array_map(static fn($v) => trim((string) $v, " \t\"'"), $cels ?: []);

            // Cabeçalho: linha sem nenhum código e com nome de coluna reconhecido.
            $temCodigo = false;
            foreach ($cels as $i => $v) {
                if (Situacao::codigoValido(Objeto::normalizarCodigo($v))) {
                    $temCodigo = true;
                    break;
                }
            }
            if (!$temCodigo && $mapa === [] && $itens === []) {
                foreach ($cels as $i => $v) {
                    $k = Situacao::semAcento(mb_strtolower(trim($v)));
                    $k = preg_replace('/\s+/', ' ', $k);
                    if (preg_match('/^(codigo|objeto|rastreio|cod\.? ?objeto|numero do objeto|etiqueta)/', $k)) {
                        $colCod = $i;
                    } elseif (isset(self::COLUNAS[$k])) {
                        $mapa[$i] = self::COLUNAS[$k];
                    } else {
                        foreach (self::COLUNAS as $nome => $campo) {
                            if (str_starts_with($k, $nome)) {
                                $mapa[$i] = $campo;
                                break;
                            }
                        }
                    }
                }
                if ($mapa !== [] || $colCod !== null) {
                    $colunas = array_values(array_unique(array_values($mapa)));
                    continue;
                }
            }
            if (!$temCodigo) {
                // Linha solta sem código (ex.: título do relatório): ignorar; token único inválido conta como inválido.
                if ($sep === null && count($cels) === 1 && preg_match('/^[A-Za-z]{2}\d/', $cels[0])) {
                    $invalid[] = strtoupper($cels[0]);
                }
                continue;
            }

            // Código: coluna do cabeçalho, senão a primeira célula com formato válido.
            $codigo = null;
            if ($colCod !== null && isset($cels[$colCod]) && Situacao::codigoValido(Objeto::normalizarCodigo($cels[$colCod]))) {
                $codigo = Objeto::normalizarCodigo($cels[$colCod]);
            } else {
                foreach ($cels as $v) {
                    $c = Objeto::normalizarCodigo($v);
                    if (Situacao::codigoValido($c)) {
                        $codigo = $c;
                        break;
                    }
                }
            }
            if ($sep === null) {
                // Texto colado: cada token é um código (ou inválido).
                foreach ($cels as $v) {
                    $c = Objeto::normalizarCodigo($v);
                    if (!Situacao::codigoValido($c)) {
                        $invalid[] = $c;
                    } elseif (!isset($vistos[$c])) {
                        $vistos[$c] = true;
                        $itens[]    = ['codigo' => $c];
                    }
                }
                continue;
            }
            if ($codigo === null || isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;
            $item = ['codigo' => $codigo];
            foreach ($mapa as $i => $campo) {
                $v = trim((string) ($cels[$i] ?? ''));
                if ($v === '') {
                    continue;
                }
                $item[$campo] = $campo === 'data_postagem' ? self::dataBr($v) : $v;
            }
            $itens[] = $item;
            if (count($itens) >= self::MAXIMO) {
                break;
            }
        }

        return ['itens' => $itens, 'invalidos' => array_values(array_unique($invalid)), 'colunas' => $colunas];
    }

    /**
     * Grava os itens e consulta os gravados. Devolve o resumo.
     *
     * @param list<array<string,mixed>> $itens
     * @param array<string,mixed>       $padrao entities_id, servico, users_id, groups_id, avisar_whatsapp, abrir_chamado
     * @return array{gravados:int, existentes:int, erros:list<string>, codigos:list<string>, consulta:?array}
     */
    public static function importar(array $itens, array $padrao, string $origem = 'lote'): array
    {
        $gravados   = 0;
        $existentes = 0;
        $erros      = [];
        $linhas     = [];
        $codigos    = [];

        foreach ($itens as $item) {
            $codigo = (string) $item['codigo'];
            if (Objeto::codigoExiste($codigo)) {
                $existentes++;
                continue;
            }
            $post = $padrao + $item;
            $post['codigo'] = $codigo;
            if (isset($item['tickets_id'])) {
                $post['tickets_id'] = preg_replace('/\D+/', '', (string) $item['tickets_id']);
            }
            [$dados, $errosItem] = Objeto::validarFormulario($post);
            if ($errosItem !== []) {
                // Campos opcionais inválidos não impedem o cadastro: tenta só com o código.
                [$dados, $errosItem] = Objeto::validarFormulario($padrao + ['codigo' => $codigo]);
                if ($errosItem !== []) {
                    $erros[] = "$codigo: " . implode(' ', $errosItem);
                    continue;
                }
                $erros[] = "$codigo: cadastrado só com o código (dados da linha inválidos: " . implode(' ', $errosItem) . ')';
            }
            $o = new Objeto();
            if (!$o->can(-1, CREATE, $dados)) {
                $erros[] = "$codigo: sem permissão para cadastrar nesta entidade.";
                continue;
            }
            if (!$o->add($dados)) {
                $erros[] = "$codigo: o GLPI não gravou o objeto.";
                continue;
            }
            $gravados++;
            $codigos[] = $codigo;
            if ($o->getFromDB($o->getID()) && count($linhas) < Rastreio::LIMITE_MANUAL) {
                $linhas[] = $o->fields;
            }
        }
        if ($gravados > 0) {
            Configuracao::log("importacao em lote: $gravados objeto(s) por " . Session::getLoginUserID());
        }

        $consulta = null;
        if ($linhas !== []) {
            try {
                $consulta = Rastreio::doGlpi()->consultar($linhas, $origem);
            } catch (\Throwable $e) {
                Configuracao::log('importacao: consulta falhou ' . $e::class . ' ' . $e->getMessage());
                $consulta = ['ok' => false, 'mensagem' => 'A consulta à API falhou (ver files/_log/postalplus.log); os objetos serão consultados pela ação automática.'];
            }
        }

        return ['gravados' => $gravados, 'existentes' => $existentes, 'erros' => $erros, 'codigos' => $codigos, 'consulta' => $consulta];
    }

    /** Separador de colunas mais provável (null = texto colado, sem colunas). */
    private static function separador(string $texto): ?string
    {
        $amostra = substr($texto, 0, 5000);
        $cont    = [';' => substr_count($amostra, ';'), "\t" => substr_count($amostra, "\t"), ',' => substr_count($amostra, ',')];
        arsort($cont);
        $sep = array_key_first($cont);
        if ($cont[$sep] === 0) {
            return null;
        }
        // Vírgula entre códigos colados ("AA…BR, BB…BR") não é coluna: só vale se houver outra coisa além de códigos.
        if ($sep === ',' && preg_match('/^[\s,A-Za-z0-9]+$/', $amostra) && !preg_match('/[A-Za-z]{3,}/', preg_replace('/[A-Za-z]{2}\d{9}[A-Za-z]{2}/', '', $amostra))) {
            return null;
        }

        return $sep;
    }

    private static function dataBr(string $v): string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return substr($v, 0, 10);
    }

    private static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }

        return $s;
    }
}
