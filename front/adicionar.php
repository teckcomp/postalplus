<?php

/**
 * Postal+ — Adicionar objetos (individual e em lote).
 *
 * Bloco 2: o formulário "Objeto individual" grava de verdade (POST nesta mesma página).
 * Bloco 3: logo depois de gravar, consulta o objeto na API Rastro (falha na consulta não desfaz o cadastro).
 * Bloco 10: importação em lote (códigos colados, CSV ou Relatório de Objetos) pela classe Importacao.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Configuracao;
use GlpiPlugin\Postalplus\Importacao;
use GlpiPlugin\Postalplus\Menu;
use GlpiPlugin\Postalplus\Objeto;
use GlpiPlugin\Postalplus\PerfilDireitos;
use GlpiPlugin\Postalplus\Rastreio;

Session::checkRight(PerfilDireitos::RIGHT_OBJETO, CREATE);

/** @var \DBmysql $DB */
global $DB;

$nav   = Menu::nav('adicionar');
$form  = Objeto::formularioPadrao();
$erros = [];

if (isset($_POST['salvar_individual'])) {
    [$dados, $erros, $form] = Objeto::validarFormulario($_POST);

    if ($erros === []) {
        $objeto = new Objeto();
        if (!$objeto->can(-1, CREATE, $dados)) {
            $erros[] = 'Sem permissão para cadastrar objetos nesta entidade.';
        } elseif ($objeto->add($dados)) {
            Configuracao::log("objeto {$dados['codigo']} cadastrado (id {$objeto->getID()}) por " . Session::getLoginUserID());
            try {
                $objeto->getFromDB($objeto->getID());
                $consulta = Rastreio::doGlpi()->consultar([$objeto->fields], 'cadastro');
                $item     = $consulta['itens'][0] ?? ['ok' => false, 'erro' => 'sem resposta'];
                $texto    = $item['ok']
                    ? "Objeto {$dados['codigo']} cadastrado e consultado: " . ($item['rotulo'] ?? 'ok') . '.'
                    : "Objeto {$dados['codigo']} cadastrado, mas a consulta à API falhou: {$item['erro']}";
                $tipo     = $item['ok'] ? INFO : WARNING;
            } catch (\Throwable $e) {
                Configuracao::log('consulta no cadastro: exceção ' . $e::class . ' ' . $e->getMessage());
                $texto = "Objeto {$dados['codigo']} cadastrado, mas a consulta à API falhou (ver files/_log/postalplus.log).";
                $tipo  = WARNING;
            }
            Session::addMessageAfterRedirect(htmlescape($texto), false, $tipo);
            Html::redirect($nav['web'] . '/front/objeto.php?codigo=' . rawurlencode($dados['codigo']));
        } else {
            $erros[] = 'Não foi possível gravar o objeto. Veja a mensagem do GLPI e o log files/_log/postalplus.log.';
        }
    }
}

// Importação em lote (Bloco 10): texto colado ou arquivo (CSV / Relatório de Objetos).
if (isset($_POST['importar_lote'])) {
    $texto = (string) ($_POST['lote_codigos'] ?? '');
    $arq   = $_FILES['lote_arquivo'] ?? null;
    if (is_array($arq) && (int) ($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $arq['tmp_name'])) {
        if ((int) $arq['size'] > 5 * 1024 * 1024) {
            Session::addMessageAfterRedirect('Arquivo maior que 5 MB.', false, ERROR);
            Html::redirect($nav['web'] . '/front/adicionar.php');
        }
        $texto = (string) file_get_contents((string) $arq['tmp_name']);
    } elseif (is_array($arq) && (int) ($arq['error'] ?? 0) !== UPLOAD_ERR_NO_FILE && (int) ($arq['error'] ?? 0) !== UPLOAD_ERR_OK) {
        Session::addMessageAfterRedirect('Falha ao receber o arquivo (código ' . (int) $arq['error'] . ').', false, ERROR);
        Html::redirect($nav['web'] . '/front/adicionar.php');
    }

    $analise = Importacao::analisar($texto);
    $resp    = (string) ($_POST['lote_responsavel'] ?? '');
    $padrao  = [
        'entities_id'   => (int) ($_POST['lote_entities_id'] ?? -1),
        'servico'       => (string) ($_POST['lote_servico'] ?? ''),
        'users_id'      => str_starts_with($resp, 'u') ? (int) substr($resp, 1) : 0,
        'groups_id'     => str_starts_with($resp, 'g') ? (int) substr($resp, 1) : 0,
        'abrir_chamado' => empty($_POST['lote_abrir_chamado']) ? 0 : 1,
    ];

    if ($analise['itens'] === []) {
        Session::addMessageAfterRedirect(htmlescape('Nenhum código de rastreio válido encontrado' . ($analise['invalidos'] !== [] ? ' (' . count($analise['invalidos']) . ' com formato inválido)' : '') . '.'), false, WARNING);
        Html::redirect($nav['web'] . '/front/adicionar.php');
    }

    $r   = Importacao::importar($analise['itens'], $padrao);
    $msg = sprintf('Importação: %d objeto(s) cadastrado(s)', $r['gravados'])
        . ($r['existentes'] > 0 ? sprintf(', %d já existia(m)', $r['existentes']) : '')
        . ($analise['invalidos'] !== [] ? sprintf(', %d código(s) inválido(s) ignorado(s)', count($analise['invalidos'])) : '')
        . ($analise['colunas'] !== [] ? ' · colunas lidas: ' . implode(', ', $analise['colunas']) : '') . '.';
    Session::addMessageAfterRedirect(htmlescape($msg), false, $r['gravados'] > 0 ? INFO : WARNING);
    if ($r['consulta'] !== null) {
        Session::addMessageAfterRedirect(htmlescape('Consulta à API: ' . $r['consulta']['mensagem']), false, !empty($r['consulta']['ok']) ? INFO : WARNING);
    }
    foreach (array_slice($r['erros'], 0, 10) as $e) {
        Session::addMessageAfterRedirect(htmlescape($e), false, WARNING);
    }
    Html::redirect($nav['web'] . '/front/painel.php');
}

// Códigos já cadastrados (para a classificação do lote). Bloco 5: só a tabela real, sem a demonstração.
$codigos = [];
foreach ($DB->request(['SELECT' => ['codigo'], 'FROM' => Objeto::getTable()]) as $r) {
    $codigos[] = (string) $r['codigo'];
}

$entidades = [];
foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $id) {
    $entidades[] = ['id' => (int) $id, 'nome' => (string) Dropdown::getDropdownName('glpi_entities', (int) $id)];
}

$grupos = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1], 'ORDER' => 'completename', 'LIMIT' => 200]) as $g) {
    $grupos[] = ['id' => (int) $g['id'], 'nome' => (string) $g['completename']];
}

$ativas = $_SESSION['glpiactiveentities'] ?? [];

Html::header('Postal+ · Adicionar objetos', '', 'tools', Menu::class, 'adicionar');

TemplateRenderer::getInstance()->display('@postalplus/adicionar.html.twig', [
    'nav'  => $nav,
    'tela' => [
        'form'         => $form,
        'erros'        => $erros,
        'entidades'    => $entidades,
        'usuario'      => ['id' => (int) Session::getLoginUserID(), 'nome' => (string) ($_SESSION['glpiname'] ?? '')],
        'grupos'       => $grupos,
        'servicos'     => Objeto::SERVICOS,
        'dd_usuario'   => User::dropdown([
            'name'    => 'users_id',
            'value'   => (int) $form['users_id'],
            'right'   => 'all',
            'entity'  => $ativas,
            'width'   => '100%',
            'rand'    => 'pp',
            'comments'=> false,
            'display' => false,
        ]),
        'dd_grupo'     => Group::dropdown([
            'name'      => 'groups_id',
            'value'     => (int) $form['groups_id'],
            'condition' => ['is_assign' => 1],
            'entity'    => $ativas,
            'width'     => '100%',
            'rand'      => 'pp',
            'comments'  => false,
            'addicon'   => false,
            'display'   => false,
        ]),
        'codigos_json' => json_encode(array_values(array_unique($codigos)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ],
]);

Html::footer();
