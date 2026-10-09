<?php

/**
 * Postal+ — Adicionar objetos (individual e em lote).
 *
 * Bloco 2: o formulário "Objeto individual" grava de verdade (POST nesta mesma página).
 * Bloco 3: logo depois de gravar, consulta o objeto na API Rastro (falha na consulta não desfaz o cadastro).
 * A importação em lote continua só classificando no navegador (gravação no Bloco 10).
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Postalplus\Configuracao;
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
