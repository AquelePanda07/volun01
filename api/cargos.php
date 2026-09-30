<?php
/**
 * api/cargos.php
 * CRUD da lista de Cargos (aba "Cargos" do Google Sheets), usada para
 * popular o campo "Cargo" do formulário de cadastro.
 *
 *   GET    /api/cargos.php           -> lista todos os cargos
 *   GET    /api/cargos.php?ativos=1  -> lista apenas os cargos ativos
 *   POST   /api/cargos.php           -> cria um cargo (JSON: {"nome": "..."})
 *   PUT    /api/cargos.php?id=5      -> atualiza um cargo (JSON)
 *   DELETE /api/cargos.php?id=5      -> exclui um cargo
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new ListaSimplesService('Cargos', ['Nome']);
$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {
    case 'GET':
        // Primeira execução: garante que o combo do formulário não fique vazio.
        $servico->semearSeVazio([
            ['nome' => 'Auxílio na Limpeza do Espaço Escolar/Zelador'],
            ['nome' => 'Profissional de Apoio Escolar PAE (cuidador)'],
        ]);
        $dados = !empty($_GET['ativos']) ? $servico->listarAtivos() : $servico->listar();
        responderJson(['sucesso' => true, 'dados' => $dados]);
        break;

    case 'POST':
        $dados = lerJsonDaRequisicao();
        if (empty($dados['nome'])) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o nome do cargo.'], 422);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Cargo cadastrado com sucesso!', 'dados' => $servico->criar($dados)], 201);
        break;

    case 'PUT':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do cargo a ser atualizado.'], 400);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Cargo atualizado com sucesso!', 'dados' => $servico->atualizar($id, lerJsonDaRequisicao())]);
        break;

    case 'DELETE':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do cargo a ser excluído.'], 400);
        }
        $servico->excluir($id);
        responderJson(['sucesso' => true, 'mensagem' => 'Cargo excluído com sucesso.']);
        break;

    default:
        responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}
