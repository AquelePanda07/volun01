<?php
/**
 * api/secretarias.php
 * CRUD da lista de Secretarias (aba "Secretarias" do Google Sheets),
 * usada para popular o campo "Secretaria" do formulário de cadastro.
 *
 *   GET    /api/secretarias.php           -> lista todas as secretarias
 *   GET    /api/secretarias.php?ativos=1  -> lista apenas as ativas
 *   POST   /api/secretarias.php           -> cria (JSON: {"nome": "...", "sigla": "..."})
 *   PUT    /api/secretarias.php?id=5      -> atualiza (JSON)
 *   DELETE /api/secretarias.php?id=5      -> exclui
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new ListaSimplesService('Secretarias', ['Nome', 'Sigla']);
$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {
    case 'GET':
        $servico->semearSeVazio([
            ['nome' => 'Secretaria Municipal de Educação', 'sigla' => ''],
            ['nome' => 'Secretaria Municipal de Saúde', 'sigla' => ''],
            ['nome' => 'Secretaria Municipal de Assistência Social', 'sigla' => ''],
        ]);
        $dados = !empty($_GET['ativos']) ? $servico->listarAtivos() : $servico->listar();
        responderJson(['sucesso' => true, 'dados' => $dados]);
        break;

    case 'POST':
        $dados = lerJsonDaRequisicao();
        if (empty($dados['nome'])) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o nome da secretaria.'], 422);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Secretaria cadastrada com sucesso!', 'dados' => $servico->criar($dados)], 201);
        break;

    case 'PUT':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id da secretaria a ser atualizada.'], 400);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Secretaria atualizada com sucesso!', 'dados' => $servico->atualizar($id, lerJsonDaRequisicao())]);
        break;

    case 'DELETE':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id da secretaria a ser excluída.'], 400);
        }
        $servico->excluir($id);
        responderJson(['sucesso' => true, 'mensagem' => 'Secretaria excluída com sucesso.']);
        break;

    default:
        responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}
