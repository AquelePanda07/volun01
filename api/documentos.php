<?php
/**
 * api/documentos.php
 * Endpoint para geração e histórico do Termo de Adesão ao Serviço
 * Voluntário (ANEXO V).
 *
 *   GET  /api/documentos.php?acao=previsualizar&id_voluntario=5
 *   GET  /api/documentos.php?acao=historico&id_voluntario=5
 *   GET  /api/documentos.php?acao=baixar&id=12
 *   GET  /api/documentos.php?acao=baixar_lote&arquivo=Termos_Adesao_20260916_153000_ab12cd.zip
 *   POST /api/documentos.php   { "idVoluntario": 5, "formato": "pdf"|"docx", "dataEmissao": "2026-09-16" }
 *   POST /api/documentos.php?acao=lote   { "idsVoluntarios": [5, 7], "formato": "pdf"|"docx", "dataEmissao": "2026-09-16" }
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

if ($metodo === 'GET' && $acao === 'baixar_lote') {
    // basename + regex impedem acesso a qualquer arquivo fora da pasta de lotes.
    $nomeArquivo = basename((string) ($_GET['arquivo'] ?? ''));
    $caminhoAbsoluto = __DIR__ . '/../documentos/lotes/' . $nomeArquivo;
    if (!preg_match('/^Termos_Adesao_[0-9_]+_[a-f0-9]+\.zip$/', $nomeArquivo) || !is_file($caminhoAbsoluto)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Arquivo do lote não foi encontrado no servidor.'], 404);
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . filesize($caminhoAbsoluto));
    readfile($caminhoAbsoluto);
    exit;
}

if ($metodo === 'POST' && $acao === 'lote') {
    $dados = lerJsonDaRequisicao();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($dados['idsVoluntarios'] ?? [])))));
    $formato = strtolower((string) ($dados['formato'] ?? ''));
    $usuarioResponsavel = trim((string) ($dados['usuarioResponsavel'] ?? '')) ?: 'Administrador';
    $dataEmissao = $dados['dataEmissao'] ?? null;

    if (!in_array($formato, ['pdf', 'docx'], true)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Formato inválido. Use "pdf" ou "docx".'], 400);
    }
    if (empty($ids)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Selecione ao menos um voluntário.'], 400);
    }

    set_time_limit(0);
    $tipoDocumento = $formato === 'pdf' ? 'Termo de Adesão (PDF)' : 'Termo de Adesão (DOCX)';
    $gerados = [];
    $falhas = [];

    foreach ($ids as $idVoluntario) {
        $voluntario = $repoVoluntarios->buscarPorId($idVoluntario);
        if (!$voluntario) {
            $falhas[] = ['idVoluntario' => $idVoluntario, 'nome' => "ID {$idVoluntario}", 'erros' => ['Voluntário não encontrado.']];
            continue;
        }

        $erros = $servico->validarParaGeracao($voluntario);
        if (!empty($erros)) {
            $falhas[] = ['idVoluntario' => $idVoluntario, 'nome' => $voluntario['nome'], 'erros' => $erros];
            continue;
        }

        try {
            $versao = $repoDocumentos->proximaVersao($idVoluntario, $tipoDocumento);
            $arquivo = $formato === 'pdf'
                ? $servico->gerarPdf($voluntario, $versao, $dataEmissao)
                : $servico->gerarDocx($voluntario, $versao, $dataEmissao);

            $repoDocumentos->registrar([
                'idVoluntario' => $idVoluntario,
                'tipoDocumento' => $arquivo['tipoDocumento'],
                'nomeArquivo' => $arquivo['nomeArquivo'],
                'caminhoArquivo' => $arquivo['caminhoArquivo'],
                'versao' => $arquivo['versao'],
                'usuarioResponsavel' => $usuarioResponsavel,
            ]);
            $gerados[] = $arquivo;
        } catch (Throwable $e) {
            registrarLog("Lote de termos: falha ao gerar para o voluntário {$idVoluntario}: " . $e->getMessage());
            $falhas[] = ['idVoluntario' => $idVoluntario, 'nome' => $voluntario['nome'], 'erros' => ['Erro ao gerar o documento: ' . $e->getMessage()]];
        }
    }

    if (empty($gerados)) {
        responderJson([
            'sucesso' => false,
            'mensagem' => 'Nenhum Termo de Adesão pôde ser gerado.',
            'falhas' => $falhas,
        ], 422);
    }

    $pastaLotes = __DIR__ . '/../documentos/lotes';
    if (!is_dir($pastaLotes)) {
        mkdir($pastaLotes, 0775, true);
    }
    // Remove lotes antigos (mais de 1 dia): os termos individuais continuam no histórico.
    foreach (glob($pastaLotes . '/*.zip') ?: [] as $antigo) {
        if (filemtime($antigo) < time() - 86400) {
            @unlink($antigo);
        }
    }

    $nomeZip = 'Termos_Adesao_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($pastaLotes . '/' . $nomeZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não foi possível criar o arquivo ZIP do lote.');
    }
    $nomesUsados = [];
    foreach ($gerados as $arquivo) {
        // Evita sobrescrever dentro do ZIP quando dois voluntários têm o mesmo nome.
        $nome = $arquivo['nomeArquivo'];
        $base = pathinfo($nome, PATHINFO_FILENAME);
        $ext = pathinfo($nome, PATHINFO_EXTENSION);
        for ($n = 2; isset($nomesUsados[$nome]); $n++) {
            $nome = "{$base}_{$n}.{$ext}";
        }
        $nomesUsados[$nome] = true;
        $zip->addFile($arquivo['caminhoAbsoluto'], $nome);
    }
    $zip->close();

    $total = count($gerados);
    responderJson([
        'sucesso' => true,
        'mensagem' => $total === 1 ? '1 Termo de Adesão gerado com sucesso!' : "{$total} Termos de Adesão gerados com sucesso!",
        'totalGerados' => $total,
        'falhas' => $falhas,
        'urlDownload' => 'api/documentos.php?acao=baixar_lote&arquivo=' . rawurlencode($nomeZip),
    ], 201);
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
