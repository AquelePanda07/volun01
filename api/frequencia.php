<?php
/**
 * api/frequencia.php
 * Quadro de Frequência (Relatório e Recibo Mensal de Atividades do Voluntário).
 *
 *   POST /api/frequencia.php?acao=gerar   -> gera UM .docx com o relatório de cada voluntário
 *        {
 *          "ano": 2026, "mes": 10, "ateDia": 29 (opcional),
 *          "idsVoluntarios": [5, 7],
 *          "ocorrencias": {"2026-10-12": "FERIADO", "2026-10-13": "PONTO FACULTATIVO"},
 *          "descricoes": {"<cargo>": "Atividades realizadas..."},
 *          "valor": "150,00" (opcional), "dataVoluntario": "", "dataCertificacao": ""
 *        }
 *   GET  /api/frequencia.php?acao=baixar&arquivo=Quadro_Frequencia_....docx
 *
 * O responsável que certifica vem do cadastro do Local (lotação) do
 * voluntário; sem responsável cadastrado, assina o(a) Secretário(a) do contrato.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$acao = $_GET['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $acao === 'baixar') {
    $caminho = QuadroFrequenciaService::caminhoArquivoGerado((string) ($_GET['arquivo'] ?? ''));
    if ($caminho === null) {
        responderJson(['sucesso' => false, 'mensagem' => 'Arquivo não encontrado. Gere o quadro novamente.'], 404);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . basename($caminho) . '"');
    header('Content-Length: ' . filesize($caminho));
    readfile($caminho);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $acao !== 'gerar') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}

$dados = lerJsonDaRequisicao();
$ano = (int) ($dados['ano'] ?? 0);
$mes = (int) ($dados['mes'] ?? 0);
$ateDia = !empty($dados['ateDia']) ? (int) $dados['ateDia'] : null;
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($dados['idsVoluntarios'] ?? [])))));

$erros = [];
if ($mes < 1 || $mes > 12 || $ano < 2000 || $ano > 2100) {
    $erros[] = 'Informe um mês e ano válidos.';
}
if ($ateDia !== null && ($ateDia < 1 || $ateDia > 31)) {
    $erros[] = 'O "até o dia" deve estar entre 1 e 31.';
}
if (empty($ids)) {
    $erros[] = 'Selecione pelo menos um voluntário.';
}
if (!empty($erros)) {
    responderJson(['sucesso' => false, 'mensagem' => $erros[0], 'erros' => $erros], 422);
}

// Ocorrências do mês: só datas válidas, com texto curto.
$ocorrencias = [];
foreach ((array) ($dados['ocorrencias'] ?? []) as $data => $texto) {
    $texto = trim((string) $texto);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data) && $texto !== '') {
        $ocorrencias[(string) $data] = mb_substr($texto, 0, 40);
    }
}
$descricoes = array_map(fn ($d) => trim((string) $d), (array) ($dados['descricoes'] ?? []));

// Responsáveis por lotação (cadastro de Locais).
$responsaveisPorLocal = [];
foreach ((new ListaSimplesService('locais', ListaSimplesService::COLUNAS_LOCAIS))->listar() as $local) {
    if (trim((string) $local['responsavel']) !== '') {
        $responsaveisPorLocal[mb_strtolower(trim((string) $local['nome']), 'UTF-8')] = $local;
    }
}

$repositorio = new VoluntarioRepository();
$servico = new QuadroFrequenciaService();
$relatorios = [];
$falhas = [];

foreach ($ids as $id) {
    $v = $repositorio->buscarPorId($id);
    if (!$v) {
        $falhas[] = ['idVoluntario' => $id, 'nome' => "Voluntário #{$id}", 'erros' => ['Voluntário não encontrado.']];
        continue;
    }

    $cargo = trim((string) ($v['cargo'] ?? ''));
    $descricao = ($descricoes[$cargo] ?? '') !== '' ? $descricoes[$cargo] : $cargo;

    $lotacao = trim((string) ($v['localPrestacao'] ?? ''));
    $local = $responsaveisPorLocal[mb_strtolower($lotacao, 'UTF-8')] ?? null;
    if ($local !== null) {
        $responsavel = [
            'nome' => mb_strtoupper(trim((string) $local['responsavel']), 'UTF-8'),
            'cargo' => trim((string) $local['cargoResponsavel']),
            'lotacao' => in_array(strtoupper((string) $local['ocultarLotacao']), ['TRUE', '1'], true) ? '' : $lotacao,
            'ato_nomeacao' => trim((string) $local['atoNomeacao']),
        ];
    } else {
        // Sem responsável na lotação: assina o(a) Secretário(a) informado(a) no contrato.
        $secretaria = trim((string) ($v['secretaria'] ?? ''));
        $responsavel = [
            'nome' => mb_strtoupper(trim((string) ($v['nomeSecretario'] ?? '')), 'UTF-8'),
            'cargo' => preg_match('/^Secretaria Municipal de (.+)$/ui', $secretaria, $m) ? 'Secretário(a) Municipal de ' . $m[1] : $secretaria,
            'lotacao' => '',
            'ato_nomeacao' => '',
        ];
    }

    $relatorio = $servico->montarRelatorio($v, [
        'ano' => $ano,
        'mes' => $mes,
        'ateDia' => $ateDia,
        'ocorrencias' => $ocorrencias,
        'descricao' => $descricao,
        'valor' => (string) ($dados['valor'] ?? ''),
        'dataVoluntario' => (string) ($dados['dataVoluntario'] ?? ''),
        'dataCertificacao' => (string) ($dados['dataCertificacao'] ?? ''),
    ], $responsavel);

    if (empty($relatorio['atividades'])) {
        $falhas[] = [
            'idVoluntario' => $id,
            'nome' => (string) $v['nome'],
            'erros' => ['O contrato não tem dias de trabalho neste mês (verifique datas de início/término e dias da semana).'],
        ];
        continue;
    }
    $relatorios[] = $relatorio;
}

if (empty($relatorios)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Nenhum relatório pôde ser gerado.', 'falhas' => $falhas], 422);
}

$arquivo = $servico->gerarDocx($relatorios, $ano, $mes);
registrarLog(sprintf('Quadro de Frequência %02d/%d gerado: %d relatório(s) -> %s', $mes, $ano, count($relatorios), $arquivo['nomeArquivo']));

responderJson([
    'sucesso' => true,
    'mensagem' => count($relatorios) === 1
        ? 'Quadro de Frequência gerado (1 voluntário).'
        : 'Quadro de Frequência gerado com ' . count($relatorios) . ' voluntários no mesmo documento.',
    'totalGerados' => count($relatorios),
    'falhas' => $falhas,
    'urlDownload' => 'api/frequencia.php?acao=baixar&arquivo=' . rawurlencode($arquivo['nomeArquivo']),
]);
