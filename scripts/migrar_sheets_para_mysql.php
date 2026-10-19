<?php
/**
 * scripts/migrar_sheets_para_mysql.php
 *
 * Copia todos os dados da planilha do Google Sheets para o MySQL,
 * mantendo os mesmos IDs (assim os vínculos voluntário -> contrato ->
 * documentos continuam valendo). A planilha NÃO é alterada.
 *
 * Uso (na pasta do projeto, com o MySQL do XAMPP ligado):
 *
 *     php scripts/migrar_sheets_para_mysql.php              # só migra se as tabelas estiverem vazias
 *     php scripts/migrar_sheets_para_mysql.php --substituir # apaga o que houver no MySQL e copia de novo
 *
 * Tudo acontece numa transação: se qualquer registro falhar, nada é gravado.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

$substituir = in_array('--substituir', $argv, true);

$origem = new GoogleSheetsService();
$destino = new MySQLService();
$pdo = $destino->pdo();

echo "Lendo a planilha do Google Sheets...\n";
$dados = [];
foreach ($destino->entidades() as $entidade) {
    $registros = $origem->listar($entidade);

    $ids = [];
    foreach ($registros as $i => $registro) {
        $id = (int) ($registro['ID'] ?? 0);
        if ($id <= 0) {
            fwrite(STDERR, "[ERRO] {$entidade}: o registro na posição " . ($i + 1) . " está sem ID. Corrija na planilha e rode de novo.\n");
            exit(1);
        }
        if (isset($ids[$id])) {
            fwrite(STDERR, "[ERRO] {$entidade}: o ID {$id} aparece mais de uma vez. Corrija na planilha e rode de novo.\n");
            exit(1);
        }
        $ids[$id] = true;
    }

    $dados[$entidade] = $registros;
    printf("  %-12s %d registro(s)\n", $entidade, count($registros));
}

// Garante que as tabelas existam (são criadas na primeira consulta) e confere se estão vazias.
foreach ($destino->entidades() as $entidade) {
    $existentes = count($destino->listar($entidade));
    if ($existentes > 0 && !$substituir) {
        fwrite(STDERR, "\n[PARADO] A tabela \"{$entidade}\" no MySQL já tem {$existentes} registro(s).\n");
        fwrite(STDERR, "Para apagar o conteúdo do MySQL e copiar tudo de novo da planilha, use --substituir.\n");
        exit(1);
    }
}

echo "\nGravando no MySQL...\n";
$pdo->beginTransaction();
try {
    foreach ($dados as $entidade => $registros) {
        $pdo->exec("DELETE FROM `{$entidade}`");
        foreach ($registros as $registro) {
            $destino->inserir($entidade, $registro);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[ERRO] Nada foi gravado: {$e->getMessage()}\n");
    exit(1);
}

echo "\nConferindo...\n";
$ok = true;
foreach ($dados as $entidade => $registros) {
    $gravados = $destino->listar($entidade);
    $iguais = count($gravados) === count($registros);
    $porId = array_column($gravados, null, 'ID');
    foreach ($registros as $registro) {
        foreach ($destino->campos($entidade) as $campo) {
            if ((string) ($registro[$campo] ?? '') !== (string) ($porId[(string) $registro['ID']][$campo] ?? null)) {
                $iguais = false;
                break 2;
            }
        }
    }
    printf("  %-12s %s\n", $entidade, $iguais ? 'OK (' . count($gravados) . ')' : 'DIFERENTE!');
    $ok = $ok && $iguais;
}

echo $ok
    ? "\nMigração concluída. O sistema já está usando o MySQL (driver em config/database.php).\n"
    : "\n[ATENÇÃO] Há diferenças entre a planilha e o MySQL. Verifique antes de usar o sistema.\n";
exit($ok ? 0 : 1);
