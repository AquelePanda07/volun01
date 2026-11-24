<?php
/**
 * api/voluntarios.php
 * Endpoint REST-like para CRUD de voluntários.
 *
 *   GET    /api/voluntarios.php           -> lista todos os voluntários
 *   GET    /api/voluntarios.php?id=5      -> busca um voluntário
 *   POST   /api/voluntarios.php           -> cria um voluntário (JSON)
 *   PUT    /api/voluntarios.php?id=5      -> atualiza um voluntário (JSON)
 *   PATCH  /api/voluntarios.php?id=5      -> marca/desmarca como "Não ativo" ({"naoAtivo": true})
 *   DELETE /api/voluntarios.php?id=5      -> exclui um voluntário
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$repositorio = new VoluntarioRepository();
$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {
    case 'GET':
        if ($id) {
            $voluntario = $repositorio->buscarPorId($id);
            if (!$voluntario) {
                responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
            }
            responderJson(['sucesso' => true, 'dados' => $voluntario]);
        }
        responderJson(['sucesso' => true, 'dados' => $repositorio->listar()]);
        break;

    case 'POST':
        $dados = lerJsonDaRequisicao();

        $erros = Validator::validarVoluntario($dados);
        if (!empty($dados['cpf']) && $repositorio->existeCpf((string) $dados['cpf'])) {
            $erros[] = 'Já existe um voluntário cadastrado com este CPF.';
        }
        if (!empty($erros)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Não foi possível salvar o voluntário.', 'erros' => $erros], 422);
        }

        $criado = $repositorio->criar($dados);
        responderJson(['sucesso' => true, 'mensagem' => 'Voluntário cadastrado com sucesso!', 'dados' => $criado], 201);
        break;

    case 'PUT':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do voluntário a ser atualizado.'], 400);
        }
        $existente = $repositorio->buscarPorId($id);
        if (!$existente) {
            responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
        }

        $dados = lerJsonDaRequisicao();
        // Sem os campos da renovação no envio, valida com os que já estão salvos.
        if (!array_key_exists('renovado', $dados)) {
            $dados += array_intersect_key($existente, array_flip(['renovado', 'terminoAnterior', 'dataRenovacao']));
        }

        $erros = Validator::validarVoluntario($dados, false);
        if (!empty($dados['cpf']) && $repositorio->existeCpf((string) $dados['cpf'], $id)) {
            $erros[] = 'Já existe um voluntário cadastrado com este CPF.';
        }
        if (!empty($erros)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Não foi possível salvar o voluntário.', 'erros' => $erros], 422);
        }

        $atualizado = $repositorio->atualizar($id, $dados);
        responderJson(['sucesso' => true, 'mensagem' => 'Voluntário atualizado com sucesso!', 'dados' => $atualizado]);
        break;

    case 'PATCH':
        // Só altera a marcação "Não ativo": não exige o cadastro completo,
        // já que serve justamente para quem desistiu ou não tem dados.
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do voluntário.'], 400);
        }
        if (!$repositorio->buscarPorId($id)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
        }
        $dados = lerJsonDaRequisicao();
        if (!array_key_exists('naoAtivo', $dados)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o campo "naoAtivo".'], 422);
        }
        $naoAtivo = filter_var($dados['naoAtivo'], FILTER_VALIDATE_BOOLEAN);
        $atualizado = $repositorio->definirNaoAtivo($id, $naoAtivo);
        responderJson([
            'sucesso' => true,
            'mensagem' => $naoAtivo ? 'Voluntário marcado como não ativo.' : 'Voluntário reativado.',
            'dados' => $atualizado,
        ]);
        break;

    case 'DELETE':
        if (!$id) {
            responderJson(['sucesso' => false, 'mensagem' => 'Informe o id do voluntário a ser excluído.'], 400);
        }
        if (!$repositorio->buscarPorId($id)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
        }
        $repositorio->excluir($id);
        responderJson(['sucesso' => true, 'mensagem' => 'Voluntário excluído com sucesso.']);
        break;

    default:
        responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}
