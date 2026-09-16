<?php
/**
 * api/documentos.php
 * Endpoint para geração e histórico do Termo de Adesão ao Serviço
 * Voluntário (ANEXO V).
 *
 *   GET  /api/documentos.php?acao=previsualizar&id_voluntario=5
 *   GET  /api/documentos.php?acao=historico&id_voluntario=5
 *   GET  /api/documentos.php?acao=baixar&id=12
 *   POST /api/documentos.php   { "idVoluntario": 5, "formato": "pdf"|"docx", "dataEmissao": "2026-09-16" }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$repoVoluntarios = new VoluntarioRepository();
$repoDocumentos = new DocumentoRepository();
$servico = new TermoAdesaoService();

$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? null;

if ($metodo === 'GET' && $acao === 'previsualizar') {
    $idVoluntario = (int) ($_GET['id_voluntario'] ?? 0);
    $voluntario = $repoVoluntarios->buscarPorId($idVoluntario);
    if (!$voluntario) {
        responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
    }

    $erros = $servico->validarParaGeracao($voluntario);
    $html = empty($erros) ? $servico->renderizarHtml($voluntario, $_GET['dataEmissao'] ?? null) : null;

    responderJson([
        'sucesso' => empty($erros),
        'erros' => $erros,
        'html' => $html,
        'voluntario' => $voluntario,
    ]);
}

if ($metodo === 'GET' && $acao === 'historico') {
    $idVoluntario = (int) ($_GET['id_voluntario'] ?? 0);
    if (!$repoVoluntarios->buscarPorId($idVoluntario)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
    }
    responderJson(['sucesso' => true, 'dados' => $repoDocumentos->listarPorVoluntario($idVoluntario)]);
}

if ($metodo === 'GET' && $acao === 'baixar') {
    $idDocumento = (int) ($_GET['id'] ?? 0);
    $documento = $repoDocumentos->buscarPorId($idDocumento);
    if (!$documento) {
        responderJson(['sucesso' => false, 'mensagem' => 'Documento não encontrado.'], 404);
    }

    $caminhoAbsoluto = __DIR__ . '/../' . $documento['caminhoArquivo'];
    if (!is_file($caminhoAbsoluto)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Arquivo do documento não foi encontrado no servidor.'], 404);
    }

    $mime = str_ends_with($documento['nomeArquivo'], '.pdf')
        ? 'application/pdf'
        : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $documento['nomeArquivo'] . '"');
    header('Content-Length: ' . filesize($caminhoAbsoluto));
    readfile($caminhoAbsoluto);
    exit;
}

if ($metodo === 'POST') {
    $dados = lerJsonDaRequisicao();
    $idVoluntario = (int) ($dados['idVoluntario'] ?? 0);
    $formato = strtolower((string) ($dados['formato'] ?? ''));
    $usuarioResponsavel = trim((string) ($dados['usuarioResponsavel'] ?? '')) ?: 'Administrador';
    $dataEmissao = $dados['dataEmissao'] ?? null;

    if (!in_array($formato, ['pdf', 'docx'], true)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Formato inválido. Use "pdf" ou "docx".'], 400);
    }

    $voluntario = $repoVoluntarios->buscarPorId($idVoluntario);
    if (!$voluntario) {
        responderJson(['sucesso' => false, 'mensagem' => 'Voluntário não encontrado.'], 404);
    }

    $erros = $servico->validarParaGeracao($voluntario);
    if (!empty($erros)) {
        responderJson([
            'sucesso' => false,
            'mensagem' => 'Não é possível gerar o Termo de Adesão: há dados obrigatórios pendentes.',
            'erros' => $erros,
        ], 422);
    }

    $tipoDocumento = $formato === 'pdf' ? 'Termo de Adesão (PDF)' : 'Termo de Adesão (DOCX)';
    $versao = $repoDocumentos->proximaVersao($idVoluntario, $tipoDocumento);

    $arquivo = $formato === 'pdf'
        ? $servico->gerarPdf($voluntario, $versao, $dataEmissao)
        : $servico->gerarDocx($voluntario, $versao, $dataEmissao);

    $registro = $repoDocumentos->registrar([
        'idVoluntario' => $idVoluntario,
        'tipoDocumento' => $arquivo['tipoDocumento'],
        'nomeArquivo' => $arquivo['nomeArquivo'],
        'caminhoArquivo' => $arquivo['caminhoArquivo'],
        'versao' => $arquivo['versao'],
        'usuarioResponsavel' => $usuarioResponsavel,
    ]);

    responderJson([
        'sucesso' => true,
        'mensagem' => 'Termo de Adesão gerado com sucesso!',
        'dados' => $registro,
        'urlDownload' => 'api/documentos.php?acao=baixar&id=' . $registro['id'],
    ], 201);
}

responderJson(['sucesso' => false, 'mensagem' => 'Requisição inválida.'], 400);
