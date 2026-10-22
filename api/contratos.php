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
 *
 * Termo de Adesão assinado ("tipo" = "termo" ou "renovacao"):
 *   POST /api/contratos.php?acao=anexar           {"idVoluntario": 5, "tipo": "termo", "arquivo": "data:application/pdf;base64,..."}
 *   POST /api/contratos.php?acao=remover_anexo    {"idVoluntario": 5, "tipo": "termo"}
 *   POST /api/contratos.php?acao=assinado         {"idVoluntario": 5, "tipo": "termo", "assinado": true}
 *   GET  /api/contratos.php?acao=anexo&id_voluntario=5&tipo=termo[&baixar=1]  -> o arquivo anexado
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

$acoesTermo = ['anexar', 'remover_anexo', 'assinado'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_GET['acao'] ?? '', $acoesTermo, true)) {
    $dados = lerJsonDaRequisicao();
    $idVoluntario = (int) ($dados['idVoluntario'] ?? 0);
    $tipo = (string) ($dados['tipo'] ?? '');
    if ($idVoluntario <= 0) {
        responderJson(['sucesso' => false, 'mensagem' => 'Informe o voluntário.'], 400);
    }

    switch ($_GET['acao']) {
        case 'anexar':
            $erros = $servico->anexarTermo($idVoluntario, $tipo, (string) ($dados['arquivo'] ?? ''));
            $mensagem = 'Termo assinado anexado.';
            break;
        case 'remover_anexo':
            $erros = $servico->removerAnexoTermo($idVoluntario, $tipo);
            $mensagem = 'Anexo removido.';
            break;
        default:
            $assinado = (bool) ($dados['assinado'] ?? false);
            $erros = $servico->marcarTermoAssinado($idVoluntario, $tipo, $assinado);
            $mensagem = $assinado ? 'Termo marcado como assinado.' : 'Marcação de assinado removida.';
    }

    if (!empty($erros)) {
        responderJson(['sucesso' => false, 'mensagem' => $erros[0], 'erros' => $erros], 422);
    }
    responderJson([
        'sucesso' => true,
        'mensagem' => $mensagem,
        'dados' => (new VoluntarioRepository())->buscarPorId($idVoluntario),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['acao'] ?? '') === 'anexo') {
    $caminho = $servico->caminhoAnexoAtual((int) ($_GET['id_voluntario'] ?? 0), (string) ($_GET['tipo'] ?? ''));
    if ($caminho === null) {
        responderJson(['sucesso' => false, 'mensagem' => 'Anexo não encontrado.'], 404);
    }
    $mime = (string) array_search(pathinfo($caminho, PATHINFO_EXTENSION), ContratoService::EXTENSOES_ANEXO, true);
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($caminho));
    header('X-Content-Type-Options: nosniff');
    header(sprintf('Content-Disposition: %s; filename="%s"', !empty($_GET['baixar']) ? 'attachment' : 'inline', basename($caminho)));
    readfile($caminho);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}

$idVoluntario = isset($_GET['id_voluntario']) ? (int) $_GET['id_voluntario'] : null;

$dados = $idVoluntario
    ? $servico->listarPorVoluntario($idVoluntario)
    : $servico->listarTodos();

responderJson(['sucesso' => true, 'dados' => $dados]);
