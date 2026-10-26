<?php
/**
 * api/anexos.php
 * Arquivo único com os documentos pessoais de cada voluntário
 * (ver services/AnexoService.php).
 *
 *   GET  /api/anexos.php                         -> o arquivo de todos os voluntários que têm um
 *   GET  /api/anexos.php?id_voluntario=5         -> o do voluntário (ou null)
 *   GET  /api/anexos.php?acao=arquivo&id_voluntario=5[&baixar=1]  -> o arquivo anexado
 *   POST /api/anexos.php?acao=anexar      {"idVoluntario": 5, "nomeOriginal": "docs.pdf", "arquivo": "data:application/pdf;base64,..."}
 *   POST /api/anexos.php?acao=documentos  {"idVoluntario": 5, "documentos": ["rg", "cpf"]}
 *   POST /api/anexos.php?acao=remover     {"idVoluntario": 5}
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new AnexoService();
$acao = $_GET['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($acao, ['anexar', 'documentos', 'remover'], true)) {
    $dados = lerJsonDaRequisicao();
    $idVoluntario = (int) ($dados['idVoluntario'] ?? 0);
    if ($idVoluntario <= 0) {
        responderJson(['sucesso' => false, 'mensagem' => 'Informe o voluntário.'], 400);
    }

    switch ($acao) {
        case 'anexar':
            $erros = $servico->anexar($idVoluntario, (string) ($dados['nomeOriginal'] ?? ''), (string) ($dados['arquivo'] ?? ''));
            $mensagem = 'Arquivo de documentos anexado.';
            break;
        case 'documentos':
            $erros = $servico->marcarDocumentos($idVoluntario, (array) ($dados['documentos'] ?? []));
            $mensagem = 'Lista de documentos atualizada.';
            break;
        default:
            $erros = $servico->remover($idVoluntario);
            $mensagem = 'Arquivo de documentos removido.';
    }

    if (!empty($erros)) {
        responderJson(['sucesso' => false, 'mensagem' => $erros[0], 'erros' => $erros], 422);
    }
    responderJson(['sucesso' => true, 'mensagem' => $mensagem, 'dados' => $servico->buscarPorVoluntario($idVoluntario)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $acao === 'arquivo') {
    $arquivo = $servico->arquivo((int) ($_GET['id_voluntario'] ?? 0));
    if ($arquivo === null) {
        responderJson(['sucesso' => false, 'mensagem' => 'Arquivo não encontrado.'], 404);
    }
    $mime = (string) array_search(pathinfo($arquivo['caminho'], PATHINFO_EXTENSION), ContratoService::EXTENSOES_ANEXO, true);
    $nome = str_replace(['"', "\r", "\n"], '', $arquivo['nome']);
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($arquivo['caminho']));
    header('X-Content-Type-Options: nosniff');
    header(sprintf(
        "Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
        !empty($_GET['baixar']) ? 'attachment' : 'inline',
        preg_replace('/[^\x20-\x7E]/', '_', $nome),
        rawurlencode($nome)
    ));
    readfile($arquivo['caminho']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}

$idVoluntario = isset($_GET['id_voluntario']) ? (int) $_GET['id_voluntario'] : null;
responderJson([
    'sucesso' => true,
    'dados' => $idVoluntario ? $servico->buscarPorVoluntario($idVoluntario) : $servico->listarTodos(),
]);
