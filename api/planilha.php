<?php
/**
 * api/planilha.php
 *
 * Botão "Planilha" do painel.
 *
 *   - Com driver 'google_sheets': redireciona (302) para a planilha do Google
 *     usada como banco de dados, montando a URL a partir de
 *     config/google-sheets.php — assim o ID não aparece no HTML/JavaScript.
 *   - Com driver 'mysql': baixa um CSV (abre direto no Excel) com cada
 *     voluntário e os dados do seu contrato mais recente.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$driver = (require __DIR__ . '/../config/database.php')['driver'];

if ($driver === 'google_sheets') {
    $config = require __DIR__ . '/../config/google-sheets.php';
    header('Location: https://docs.google.com/spreadsheets/d/' . rawurlencode((string) $config['spreadsheet_id']) . '/edit', true, 302);
    exit;
}

configurarTratamentoDeErros();

$repositorio = repositorioDados();

// Contrato mais recente (maior ID) de cada voluntário.
$contratoPorVoluntario = [];
foreach ($repositorio->getContratos() as $contrato) {
    $idVoluntario = (string) $contrato['ID Voluntario'];
    if (!isset($contratoPorVoluntario[$idVoluntario]) || (int) $contrato['ID'] > (int) $contratoPorVoluntario[$idVoluntario]['ID']) {
        $contratoPorVoluntario[$idVoluntario] = $contrato;
    }
}

$camposVoluntario = array_values(array_diff(
    array_keys($repositorio->getVoluntarios()[0] ?? []) ?: ['ID', 'Nome'],
    ['Foto']
));
$camposContrato = ['Cargo', 'Local Prestacao', 'Carga Horaria', 'Data Inicio', 'Data Termino',
    'Horario Inicio', 'Horario Termino', 'Dias Semana', 'Secretaria', 'Nome Secretario', 'Status'];

/** Evita que o Excel interprete um valor como fórmula (CSV injection). */
$celula = static function ($valor): string {
    $texto = (string) $valor;
    return preg_match('/^[=+@\t\r]|^-[^0-9]/', $texto) ? "'" . $texto : $texto;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="voluntarios_' . date('Y-m-d') . '.csv"');

$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF"); // BOM: faz o Excel reconhecer os acentos (UTF-8)
fputcsv($saida, array_merge($camposVoluntario, $camposContrato), ';');

foreach ($repositorio->getVoluntarios() as $voluntario) {
    $contrato = $contratoPorVoluntario[(string) $voluntario['ID']] ?? [];
    if ($contrato) {
        // A coluna "Status" gravada fica desatualizada com o tempo: sempre recalcula pelas datas.
        $contrato['Status'] = ContratoService::calcularStatus((string) $contrato['Data Inicio'], (string) $contrato['Data Termino']);
    }
    $linha = [];
    foreach ($camposVoluntario as $campo) {
        $linha[] = $celula($voluntario[$campo] ?? '');
    }
    foreach ($camposContrato as $campo) {
        $linha[] = $celula($contrato[$campo] ?? '');
    }
    fputcsv($saida, $linha, ';');
}
fclose($saida);
