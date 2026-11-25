<?php
/**
 * api/lista-empenhos.php
 * Leitura da Lista de Empenhos (entidade "lista_empenhos"), usada pela
 * aba "Lista da planilha" de empenhos.html. Os dados entram pelo scripts/importar_empenhos.php;
 * por isso esta rota é somente leitura.
 *
 *   GET /api/lista-empenhos.php           -> todas as linhas
 *   GET /api/lista-empenhos.php?ano=2026  -> só as do ano
 *
 * Cada linha: {"ID", "ID Voluntario", "Nome", "Lotacao", "Ano",
 * "Empenhos": [{"numero", "valor"}], "Total Empenhado", "Pago", "Anulado",
 * "Saldo", "Situacao"} — valores em reais com ponto decimal ("8400.00").
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método não suportado.'], 405);
}

$linhas = repositorioDados()->listar('lista_empenhos');

$ano = trim((string) ($_GET['ano'] ?? ''));
if ($ano !== '') {
    $linhas = array_values(array_filter($linhas, fn (array $l) => (string) $l['Ano'] === $ano));
}

$linhas = array_map(function (array $l) {
    $empenhos = json_decode((string) $l['Empenhos'], true);
    $l['Empenhos'] = is_array($empenhos) ? $empenhos : [];
    return $l;
}, $linhas);

responderJson(['sucesso' => true, 'dados' => $linhas]);
