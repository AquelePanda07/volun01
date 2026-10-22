<?php
/**
 * api/contratos.php
 * Consulta de contratos (aba "Contratos" do Google Sheets) e renovação.
 * A criação/edição/exclusão de contratos acontece sempre em conjunto com
 * o voluntário, através de api/voluntarios.php (ver
 * services/ContratoService.php e src/VoluntarioRepository.php), para
 * respeitar a regra de "não criar um novo registro quando a intenção for
 * editar" (especificação, seção 14).
 *
 *   GET  /api/contratos.php                      -> lista todos os contratos
 *   GET  /api/contratos.php?id_voluntario=5       -> histórico de contratos do voluntário
 *   POST /api/contratos.php?acao=renovar          -> marca o contrato vigente como renovado
 *        {"idVoluntario": 5, "novaDataTermino": "2027-06-30"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new ContratoService();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['acao'] ?? '') === 'renovar') {
    $dados = lerJsonDaRequisicao();
    $idVoluntario = (int) ($dados['idVoluntario'] ?? 0);
    if ($idVoluntario <= 0) {
        responderJson(['sucesso' => false, 'mensagem' => 'Informe o voluntário.'], 400);
    }

    $erros = $servico->renovar($idVoluntario, trim((string) ($dados['novaDataTermino'] ?? '')));
    if (!empty($erros)) {
        responderJson(['sucesso' => false, 'mensagem' => $erros[0], 'erros' => $erros], 422);
    }
    responderJson([
        'sucesso' => true,
        'mensagem' => 'Contrato renovado com sucesso!',
        'dados' => (new VoluntarioRepository())->buscarPorId($idVoluntario),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}

$idVoluntario = isset($_GET['id_voluntario']) ? (int) $_GET['id_voluntario'] : null;

$dados = $idVoluntario
    ? $servico->listarPorVoluntario($idVoluntario)
    : $servico->listarTodos();

responderJson(['sucesso' => true, 'dados' => $dados]);
