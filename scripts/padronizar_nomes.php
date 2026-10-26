<?php
/**
 * scripts/padronizar_nomes.php
 *
 * Padroniza maiúsculas/minúsculas dos nomes já cadastrados
 * ("adriana lima ponciano santos" -> "Adriana Lima Ponciano Santos"),
 * com a mesma regra usada ao salvar (Validator::formatarNome). Só a
 * coluna "Nome" é alterada.
 *
 * Uso:
 *     php scripts/padronizar_nomes.php              # simulação: só mostra o que mudaria
 *     php scripts/padronizar_nomes.php --executar   # grava
 *
 * As gravações são espaçadas (~1 por segundo) por causa do limite de
 * escrita da API do Google Sheets.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

$executar = in_array('--executar', $argv, true);
$dados = repositorioDados();

$alteracoes = [];
foreach ($dados->getVoluntarios() as $linha) {
    $atual = (string) ($linha['Nome'] ?? '');
    $novo = Validator::formatarNome($atual);
    if ($novo !== '' && $novo !== $atual) {
        $alteracoes[] = [(int) $linha['ID'], $atual, $novo];
    }
}

if (empty($alteracoes)) {
    echo "Todos os nomes já estão padronizados.\n";
    exit(0);
}

foreach ($alteracoes as [$id, $atual, $novo]) {
    echo "#{$id}: \"{$atual}\" -> \"{$novo}\"\n";
    if ($executar) {
        $dados->updateVoluntario($id, ['Nome' => $novo]);
        usleep(1100000);
    }
}

echo "\n" . count($alteracoes) . ' nome(s) ' . ($executar ? 'atualizado(s).' : 'seriam alterados. Rode com --executar para gravar.') . "\n";
