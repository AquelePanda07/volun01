<?php
/**
 * api/locais.php
 * CRUD da lista de Locais de prestação de serviço (aba "Locais" do
 * Google Sheets), usada para popular o campo "Local de prestação de
 * serviço" do formulário de cadastro.
 *
 *   GET    /api/locais.php           -> lista todos os locais
 *   GET    /api/locais.php?ativos=1  -> lista apenas os ativos
 *   POST   /api/locais.php           -> cria (JSON: {"nome": "...", "endereco": "..."})
 *   PUT    /api/locais.php?id=5      -> atualiza (JSON)
 *   DELETE /api/locais.php?id=5      -> exclui
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new ListaSimplesService('locais', ['Nome', 'Endereco']);
$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {
    case 'GET':
        // Primeira execução: garante que o combo do formulário não fique vazio.
        $servico->semearSeVazio([
            ['nome' => 'EMEI Pequeno Príncipe', 'endereco' => ''],
            ['nome' => 'EMEF Dr. Custódio', 'endereco' => ''],
            ['nome' => 'Creche Pequeninos de Cristo', 'endereco' => ''],
            ['nome' => 'EMEF Sossego da Mamãe', 'endereco' => ''],
            ['nome' => 'EMEF Cecília Meireles', 'endereco' => ''],
        ]);
        $dados = !empty($_GET['ativos']) ? $servico->listarAtivos() : $servico->listar();
        responderJson(['sucesso' => true, 'dados' => $dados]);
        break;

    case 'POST':
        $dados = lerJsonDaRequisicao();
        if (empty($dados['nome'])) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o nome do local.'], 422);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Local cadastrado com sucesso!', 'dados' => $servico->criar($dados)], 201);
        break;

    case 'PUT':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do local a ser atualizado.'], 400);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Local atualizado com sucesso!', 'dados' => $servico->atualizar($id, lerJsonDaRequisicao())]);
        break;

    case 'DELETE':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do local a ser excluído.'], 400);
        }
        $servico->excluir($id);
        responderJson(['sucesso' => true, 'mensagem' => 'Local excluído com sucesso.']);
        break;

    default:
        responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}
