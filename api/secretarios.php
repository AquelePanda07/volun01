<?php
/**
 * api/secretarios.php
 * CRUD da lista de nomes de Secretários (aba "Secretarios" do Google
 * Sheets), usada para popular o campo "Nome do Secretário" do
 * formulário de cadastro. O vínculo "ID Secretaria" é opcional (fica em
 * branco quando o secretário não estiver associado a uma secretaria
 * específica na lista).
 *
 *   GET    /api/secretarios.php               -> lista todos os secretários
 *   GET    /api/secretarios.php?ativos=1       -> lista apenas os ativos
 *   POST   /api/secretarios.php                -> cria (JSON: {"nome": "...", "idSecretaria": "..."})
 *   PUT    /api/secretarios.php?id=5           -> atualiza (JSON)
 *   DELETE /api/secretarios.php?id=5           -> exclui
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new ListaSimplesService('Secretarios', ['ID Secretaria', 'Nome']);
$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {
    case 'GET':
        $servico->semearSeVazio([
            ['nome' => 'Luciana Rodrigues Fontinele', 'idSecretaria' => ''],
            ['nome' => 'Rodolpho Marins de Lima Arco', 'idSecretaria' => ''],
        ]);
        $dados = !empty($_GET['ativos']) ? $servico->listarAtivos() : $servico->listar();
        responderJson(['sucesso' => true, 'dados' => $dados]);
        break;

    case 'POST':
        $dados = lerJsonDaRequisicao();
        if (empty($dados['nome'])) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o nome do secretário.'], 422);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Secretário cadastrado com sucesso!', 'dados' => $servico->criar($dados)], 201);
        break;

    case 'PUT':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do secretário a ser atualizado.'], 400);
        }
        responderJson(['sucesso' => true, 'mensagem' => 'Secretário atualizado com sucesso!', 'dados' => $servico->atualizar($id, lerJsonDaRequisicao())]);
        break;

    case 'DELETE':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do secretário a ser excluído.'], 400);
        }
        $servico->excluir($id);
        responderJson(['sucesso' => true, 'mensagem' => 'Secretário excluído com sucesso.']);
        break;

    default:
        responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}
