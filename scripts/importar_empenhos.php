<?php
/**
 * scripts/importar_empenhos.php
 *
 * Importa a "Lista de voluntários e valores — Empenhos <ano>" (arquivo .md
 * com uma tabela por lotação) para a entidade "lista_empenhos", mostrada em
 * na aba "Lista da planilha" de empenhos.html.
 *
 * Como o arquivo é lido:
 *   - "## <LOTAÇÃO>" abre a tabela de uma lotação ("## Total geral" não é lotação);
 *   - cada linha com número na 1ª coluna é um voluntário;
 *   - "Empenhos" vem como "736: 8.400,00<br>2657: 1.505,00";
 *   - a linha "**Total (N voluntários)**" de cada lotação e o "Total geral" do fim
 *     são conferidos com a soma das linhas: se algo não bater, nada é gravado.
 *
 * Voluntários: cada linha é ligada ao cadastro pelo nome, sem acentos e sem
 * diferença de maiúsculas/espaços. Nomes não encontrados são gravados sem
 * "ID Voluntario" e listados no fim.
 *
 * Uso (na pasta do projeto):
 *   php scripts/importar_empenhos.php                 # simula: só mostra o relatório
 *   php scripts/importar_empenhos.php --executar      # grava (substitui as linhas do mesmo ano)
 *   Opções: --arquivo=dados/LISTA_VOLUNTARIOS_EMPENHOS_2026.md  --ano=2026
 *
 * O arquivo tem dados pessoais: mantenha-o na pasta dados/ (fora do git).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

const ENTIDADE = 'lista_empenhos';

// ----------------------------------------------------------------------
// Argumentos
// ----------------------------------------------------------------------
$opcoes = [
    'arquivo' => __DIR__ . '/../dados/LISTA_VOLUNTARIOS_EMPENHOS_2026.md',
    'ano' => '',
];
$executar = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(arquivo|ano)=(.+)$/', $arg, $m)) {
        $opcoes[$m[1]] = $m[2];
    } elseif ($arg === '--executar') {
        $executar = true;
    } elseif ($arg !== '--simular') {
        sair("Opção desconhecida: {$arg}");
    }
}

$arquivo = $opcoes['arquivo'];
if (!is_file($arquivo)) {
    sair("Arquivo não encontrado: {$arquivo}");
}
$texto = (string) file_get_contents($arquivo);

// Ano: --ano, senão o do título ("# ... Empenhos 2026").
$ano = $opcoes['ano'];
if ($ano === '' && preg_match('/^#\s.*?\b(20\d{2})\b/m', $texto, $m)) {
    $ano = $m[1];
}
if (!preg_match('/^\d{4}$/', $ano)) {
    sair('Não foi possível descobrir o ano pelo título do arquivo: informe --ano=AAAA.');
}

// ----------------------------------------------------------------------
// Leitura do .md
// ----------------------------------------------------------------------
$linhas = [];       // voluntários lidos
$erros = [];        // impedem a gravação
$avisos = [];       // só informativos
$subtotais = [];    // lotação => totais declarados no arquivo
$totalGeral = null; // totais declarados no "Total geral"
$lotacao = null;

foreach (preg_split('/\R/', $texto) as $n => $bruta) {
    $numLinha = $n + 1;
    $linha = trim($bruta);

    if (preg_match('/^##\s+(.+)$/', $linha, $m)) {
        $titulo = trim($m[1]);
        $lotacao = mb_strtolower($titulo, 'UTF-8') === 'total geral' ? null : $titulo;
        continue;
    }

    if (str_contains($linha, 'voluntários**') && str_contains($linha, 'Empenhado')) {
        $totalGeral = lerTotalGeral($linha, $numLinha, $erros);
        continue;
    }

    if (!str_starts_with($linha, '|') || $lotacao === null) {
        continue;
    }
    $celulas = array_map('trim', explode('|', trim($linha, '|')));
    if (count($celulas) !== 8) {
        continue;
    }
    [$num, $nome, $empenhosTxt, $empenhado, $pago, $anulado, $saldo, $situacao] = $celulas;

    // Subtotal da lotação: | | **Total (25 voluntários)** | | **244.428,33** | ...
    if ($num === '' && preg_match('/\*\*Total \((\d+) voluntários\)\*\*/u', $nome, $m)) {
        $subtotais[$lotacao] = [
            'quantidade' => (int) $m[1],
            'empenhado' => centavos(limparNegrito($empenhado), $numLinha, $erros),
            'pago' => centavos(limparNegrito($pago), $numLinha, $erros),
            'anulado' => centavos(limparNegrito($anulado), $numLinha, $erros),
            'saldo' => centavos(limparNegrito($saldo), $numLinha, $erros),
        ];
        continue;
    }

    if (!ctype_digit($num)) {
        continue; // cabeçalho ou separador da tabela
    }

    $registro = [
        'linha' => $numLinha,
        'nome' => preg_replace('/\s+/u', ' ', $nome),
        'lotacao' => $lotacao,
        'empenhos' => [],
        'empenhado' => centavos($empenhado, $numLinha, $erros),
        'pago' => centavos($pago, $numLinha, $erros),
        'anulado' => centavos($anulado, $numLinha, $erros),
        'saldo' => centavos($saldo, $numLinha, $erros),
        'situacao' => $situacao,
    ];

    foreach (preg_split('/<br\s*\/?>/i', $empenhosTxt) as $parte) {
        $parte = trim($parte);
        if ($parte === '') {
            continue;
        }
        if (!preg_match('/^(\d+)\s*:\s*(.+)$/', $parte, $m)) {
            $erros[] = "Linha {$numLinha} ({$registro['nome']}): empenho ilegível \"{$parte}\".";
            continue;
        }
        $registro['empenhos'][] = ['numero' => $m[1], 'valor' => centavos($m[2], $numLinha, $erros)];
    }

    if (!in_array($situacao, ['Ativo', 'Anulado'], true)) {
        $erros[] = "Linha {$numLinha} ({$registro['nome']}): situação \"{$situacao}\" (esperado Ativo ou Anulado).";
    }

    // Conferências da própria linha (só avisam: o valor gravado é o do arquivo).
    $somaEmpenhos = array_sum(array_column($registro['empenhos'], 'valor'));
    if ($somaEmpenhos !== $registro['empenhado']) {
        $avisos[] = "Linha {$numLinha} ({$registro['nome']}): soma dos empenhos " . br($somaEmpenhos)
            . ' ≠ total empenhado ' . br($registro['empenhado']) . '.';
    }
    $saldoCalculado = $registro['empenhado'] - $registro['pago'] - $registro['anulado'];
    if ($saldoCalculado !== $registro['saldo']) {
        $avisos[] = "Linha {$numLinha} ({$registro['nome']}): empenhado − pago − anulado = " . br($saldoCalculado)
            . ', mas o saldo no arquivo é ' . br($registro['saldo']) . '.';
    }

    $linhas[] = $registro;
}

if (!$linhas) {
    sair('Nenhum voluntário encontrado no arquivo.');
}

// ----------------------------------------------------------------------
// Conferência dos totais (por lotação e geral)
// ----------------------------------------------------------------------
$campos = ['empenhado', 'pago', 'anulado', 'saldo'];
$porLotacao = [];
foreach ($linhas as $l) {
    $t = &$porLotacao[$l['lotacao']];
    $t['quantidade'] = ($t['quantidade'] ?? 0) + 1;
    foreach ($campos as $c) {
        $t[$c] = ($t[$c] ?? 0) + $l[$c];
    }
    unset($t);
}

foreach ($porLotacao as $nomeLotacao => $calculado) {
    $declarado = $subtotais[$nomeLotacao] ?? null;
    if ($declarado === null) {
        $erros[] = "Lotação \"{$nomeLotacao}\" sem linha de total no arquivo.";
        continue;
    }
    foreach (array_merge(['quantidade'], $campos) as $c) {
        if ($declarado[$c] !== $calculado[$c]) {
            $fmt = $c === 'quantidade' ? 'strval' : 'br';
            $erros[] = "Lotação \"{$nomeLotacao}\": {$c} somado = {$fmt($calculado[$c])}, no arquivo = {$fmt($declarado[$c])}.";
        }
    }
}

$geral = ['quantidade' => count($linhas)];
foreach ($campos as $c) {
    $geral[$c] = array_sum(array_column($linhas, $c));
}
if ($totalGeral === null) {
    $erros[] = 'Linha "Total geral" não encontrada no arquivo.';
} else {
    foreach (array_merge(['quantidade'], $campos) as $c) {
        if ($totalGeral[$c] !== $geral[$c]) {
            $fmt = $c === 'quantidade' ? 'strval' : 'br';
            $erros[] = "Total geral: {$c} somado = {$fmt($geral[$c])}, no arquivo = {$fmt($totalGeral[$c])}.";
        }
    }
}

// ----------------------------------------------------------------------
// Ligação com o cadastro de voluntários (pelo nome)
// ----------------------------------------------------------------------
$repo = repositorioDados();
$cadastro = [];
foreach ($repo->getVoluntarios() as $v) {
    $cadastro[chaveNome((string) $v['Nome'])][] = (int) $v['ID'];
}

$naoEncontrados = [];
$ambiguos = [];
foreach ($linhas as &$l) {
    $ids = $cadastro[chaveNome($l['nome'])] ?? [];
    $l['id_voluntario'] = count($ids) === 1 ? (string) $ids[0] : '';
    if (!$ids) {
        $naoEncontrados[] = "{$l['nome']} ({$l['lotacao']})";
    } elseif (count($ids) > 1) {
        $ambiguos[] = "{$l['nome']} ({$l['lotacao']}): IDs " . implode(', ', $ids);
    }
}
unset($l);

// ----------------------------------------------------------------------
// Monta os registros (validando o tamanho do JSON de empenhos)
// ----------------------------------------------------------------------
$registros = [];
foreach ($linhas as $l) {
    $empenhosJson = json_encode(
        array_map(fn ($e) => ['numero' => $e['numero'], 'valor' => reais($e['valor'])], $l['empenhos']),
        JSON_UNESCAPED_UNICODE
    );
    if (strlen($empenhosJson) > 255) {
        $erros[] = "Linha {$l['linha']} ({$l['nome']}): lista de empenhos longa demais para a coluna.";
    }
    $registros[] = [
        'ID Voluntario' => $l['id_voluntario'],
        'Nome' => $l['nome'],
        'Lotacao' => $l['lotacao'],
        'Ano' => $ano,
        'Empenhos' => $empenhosJson,
        'Total Empenhado' => reais($l['empenhado']),
        'Pago' => reais($l['pago']),
        'Anulado' => reais($l['anulado']),
        'Saldo' => reais($l['saldo']),
        'Situacao' => $l['situacao'],
    ];
}

// ----------------------------------------------------------------------
// Relatório
// ----------------------------------------------------------------------
echo "Arquivo: " . realpath($arquivo) . "\nAno: {$ano}\n\n";
echo "== Lotações ==\n";
foreach ($porLotacao as $nomeLotacao => $t) {
    printf("  %-75s %3d  empenhado %14s  saldo %12s\n", $nomeLotacao, $t['quantidade'], br($t['empenhado']), br($t['saldo']));
}
echo "\n== Total geral ==\n";
printf("  %d voluntários · Empenhado %s · Pago %s · Anulado %s · Saldo %s\n",
    $geral['quantidade'], br($geral['empenhado']), br($geral['pago']), br($geral['anulado']), br($geral['saldo']));
echo $totalGeral !== null && !array_filter($erros, fn ($e) => str_starts_with($e, 'Total geral') || str_starts_with($e, 'Lotação'))
    ? "  Confere com os totais do arquivo.\n" : "  NÃO confere com os totais do arquivo (ver erros).\n";

$ligados = count(array_filter($registros, fn ($r) => $r['ID Voluntario'] !== ''));
echo "\n== Cadastro ==\n  {$ligados} de " . count($registros) . " linhas ligadas a um voluntário cadastrado.\n";

secao('Nomes não encontrados no cadastro', $naoEncontrados);
secao('Nomes com mais de um voluntário no cadastro (gravados sem ligação)', $ambiguos);
secao('Avisos', $avisos);
secao('Erros', $erros);

if ($erros) {
    sair("\nHá erros: nada foi gravado.");
}
if (!$executar) {
    echo "\nSimulação concluída: nada foi gravado. Rode com --executar para gravar.\n";
    exit(0);
}

// ----------------------------------------------------------------------
// Gravação: substitui as linhas do mesmo ano (tudo ou nada no MySQL)
// ----------------------------------------------------------------------
$existentes = $repo->listar(ENTIDADE); // antes da transação (cria a tabela se preciso)
$removidas = 0;
$repo->emTransacao(function () use ($repo, $existentes, $registros, $ano, &$removidas) {
    foreach ($existentes as $r) {
        if ((string) $r['Ano'] === $ano) {
            $repo->excluir(ENTIDADE, (int) $r['ID']);
            $removidas++;
        }
    }
    foreach ($registros as $r) {
        $repo->inserir(ENTIDADE, $r);
    }
});

echo "\nGravado: " . count($registros) . " linhas de {$ano}" . ($removidas ? " (substituídas {$removidas} linhas anteriores)" : '') . ".\n";

// ======================================================================
// Funções auxiliares
// ======================================================================

function sair(string $mensagem): never
{
    fwrite(STDERR, $mensagem . "\n");
    exit(1);
}

function secao(string $titulo, array $itens): void
{
    echo "\n== {$titulo} ==\n";
    echo $itens ? '  - ' . implode("\n  - ", $itens) . "\n" : "  Nenhum.\n";
}

function limparNegrito(string $valor): string
{
    return trim(str_replace('*', '', $valor));
}

/** "8.400,00" -> 840000 (centavos). Registra erro e devolve 0 se o valor for ilegível. */
function centavos(string $valor, int $numLinha, array &$erros): int
{
    $valor = trim($valor);
    if (!preg_match('/^-?\d{1,3}(\.\d{3})*,\d{2}$/', $valor)) {
        $erros[] = "Linha {$numLinha}: valor ilegível \"{$valor}\".";
        return 0;
    }
    $negativo = str_starts_with($valor, '-');
    $digitos = str_replace(['-', '.', ','], '', $valor);
    return ($negativo ? -1 : 1) * (int) $digitos;
}

/** 840000 -> "8400.00" (formato gravado no banco). */
function reais(int $centavos): string
{
    $sinal = $centavos < 0 ? '-' : '';
    $centavos = abs($centavos);
    return $sinal . intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
}

/** 840000 -> "8.400,00" (relatório). */
function br(int $centavos): string
{
    return number_format($centavos / 100, 2, ',', '.');
}

/** "**99 voluntários** · Empenhado **933.104,16** · Pago **...** · Anulado **...** · Saldo **...**" */
function lerTotalGeral(string $linha, int $numLinha, array &$erros): ?array
{
    $ok = preg_match('/\*\*(\d+) voluntários\*\*/u', $linha, $q);
    $valores = [];
    foreach (['empenhado' => 'Empenhado', 'pago' => 'Pago', 'anulado' => 'Anulado', 'saldo' => 'Saldo'] as $chave => $rotulo) {
        if (!preg_match('/' . $rotulo . '\s+\*\*([\d.,-]+)\*\*/u', $linha, $m)) {
            $ok = false;
            break;
        }
        $valores[$chave] = centavos($m[1], $numLinha, $erros);
    }
    if (!$ok) {
        $erros[] = "Linha {$numLinha}: não foi possível ler o Total geral.";
        return null;
    }
    return ['quantidade' => (int) $q[1]] + $valores;
}

/** Nome para comparação: sem acentos, maiúsculas e espaços repetidos. */
function chaveNome(string $nome): string
{
    $nome = mb_strtoupper(trim($nome), 'UTF-8');
    $nome = strtr($nome, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ]);
    return (string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $nome));
}
