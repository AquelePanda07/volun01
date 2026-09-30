<?php
/**
 * api/contratos.php
 * Endpoint somente leitura para consulta de contratos (aba "Contratos"
 * do Google Sheets). A criação/edição/exclusão de contratos acontece
 * sempre em conjunto com o voluntário, através de api/voluntarios.php
 * (ver services/ContratoService.php e src/VoluntarioRepository.php),
 * para respeitar a regra de "não criar um novo registro quando a
 * intenção for editar" (especificação, seção 14).
 *
 *   GET /api/contratos.php                      -> lista todos os contratos
 *   GET /api/contratos.php?id_voluntario=5       -> histórico de contratos do voluntário
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}

$servico = new ContratoService();
$idVoluntario = isset($_GET['id_voluntario']) ? (int) $_GET['id_voluntario'] : null;

$dados = $idVoluntario
    ? $servico->listarPorVoluntario($idVoluntario)
    : $servico->listarTodos();

responderJson(['sucesso' => true, 'dados' => $dados]);
