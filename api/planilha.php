<?php
/**
 * api/planilha.php
 * Redireciona para a planilha do Google Sheets usada como banco de dados,
 * montando a URL a partir de config/google-sheets.php — assim o ID da
 * planilha não precisa aparecer no HTML/JavaScript.
 *
 *   GET /api/planilha.php  -> 302 para https://docs.google.com/spreadsheets/d/<ID>/edit
 */

declare(strict_types=1);

$config = require __DIR__ . '/../config/google-sheets.php';

header('Location: https://docs.google.com/spreadsheets/d/' . rawurlencode((string) $config['spreadsheet_id']) . '/edit', true, 302);
