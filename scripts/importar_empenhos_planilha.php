<?php
/**
 * scripts/importar_empenhos_planilha.php
 *
 * Importa a planilha antiga "CONTROLE DE EMPENHOS VOLUNTARIOS <ano>.xlsx"
 * para o Controle de Empenhos (tabelas "empenhos" e "movimentos_empenho").
 *
 * Como a planilha é lida:
 *   - Uma seção por lotação (título na coluna B, cabeçalho "EMPENHO 1" logo abaixo).
 *   - Uma linha por voluntário: até 5 empenhos (nº + valor) e os meses jan…dez.
 *   - Só o quadro (colunas B a Z) é lido: o que está da coluna AA em diante é
 *     outro assunto, exceto a marcação "JÁ FOI ANULADO", que só é conferida.
 *   - "-" e células vazias valem zero. Texto num mês (ex.: "anular") vira aviso.
 *   - Valor negativo num mês = anulação. Um "− X" fixo na fórmula do TOTAL do
 *     1º semestre (coluna K) é a anulação de julho que a planilha comenta.
 *   - Divisão do pagamento entre empenhos, nesta ordem:
 *       1. comentário da célula ("1295 do empenho 748 / 245 do empenho 2658"),
 *          quando os números citados existem na linha e a soma bate;
 *       2. parcelas da fórmula (=1295+245), cada uma num empenho, do mais antigo;
 *       3. distribuição automática: do empenho mais antigo com saldo.
 *
 * Voluntários: a linha é ligada ao cadastro pelo nome (sem acentos, espaços
 * extras/\xa0, maiúsculas e números no fim, como "FULANA DE TAL 290").
 * Nomes com grafia diferente: confirme em importacao/mapa_nomes.json
 * ({"NOME NA PLANILHA": ID do voluntário, ...}; null = pular a linha). Na
 * primeira execução o script cria esse arquivo com as sugestões para revisar.
 *
 * Uso:
 *   php scripts/importar_empenhos_planilha.php importacao/planilha.xlsx --simular    # só o relatório
 *   php scripts/importar_empenhos_planilha.php importacao/planilha.xlsx --executar   # grava
 *   Opções: --ano=2026  --mapa=importacao/mapa_nomes.json  --substituir (apaga os empenhos do ano antes)
 *
 * A planilha tem dados pessoais: mantenha-a na pasta importacao/ (fora do git).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

// ----------------------------------------------------------------------
// Argumentos
// ----------------------------------------------------------------------
$arquivo = '';
$opcoes = ['ano' => '2026', 'mapa' => __DIR__ . '/../importacao/mapa_nomes.json'];
$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(ano|mapa)=(.+)$/', $arg, $m)) {
        $opcoes[$m[1]] = $m[2];
    } elseif (str_starts_with($arg, '--')) {
        $flags[substr($arg, 2)] = true;
    } else {
        $arquivo = $arg;
    }
}
$executar = !empty($flags['executar']) && empty($flags['simular']);
$ano = (int) $opcoes['ano'];
if ($arquivo === '' || !is_file($arquivo)) {
    fwrite(STDERR, "Uso: php scripts/importar_empenhos_planilha.php planilha.xlsx (--simular | --executar) [--ano=2026] [--mapa=arquivo.json] [--substituir]\n");
    exit(1);
}
if (empty($flags['executar']) && empty($flags['simular'])) {
    echo "(Nenhum modo informado: rodando em --simular. Use --executar para gravar.)\n";
}

// ----------------------------------------------------------------------
// Utilitários
// ----------------------------------------------------------------------
$c = fn ($v): ?int => EmpenhoService::centavos($v);
$br = fn (int $centavos): string => EmpenhoService::br($centavos);

/** Nome para comparação: sem acento, maiúsculo, espaços simples, sem números no fim. */
function normalizarNome(string $nome): string
{
    $nome = str_replace(["\xc2\xa0", '\xa0'], ' ', $nome);
    $nome = strtoupper((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper(trim($nome), 'UTF-8')));
    $nome = (string) preg_replace('/[\s\d]+$/', '', $nome);
    $nome = (string) preg_replace('/[^A-Z ]/', '', $nome);
    return trim((string) preg_replace('/\s+/', ' ', $nome));
}

/**
 * Semelhança entre dois nomes (0 a 1): palavras que batem (aceita 1–2 letras
 * de diferença) sobre o total de palavras do nome maior. O primeiro nome
 * precisa bater.
 */
function semelhancaNome(string $a, string $b): float
{
    $ignorar = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E'];
    $pa = array_values(array_diff(explode(' ', $a), $ignorar));
    $pb = array_values(array_diff(explode(' ', $b), $ignorar));
    if (!$pa || !$pb) {
        return 0.0;
    }
    $parecida = fn (string $x, string $y) => $x === $y || levenshtein($x, $y) <= (min(strlen($x), strlen($y)) >= 6 ? 2 : 1);
    if (!$parecida($pa[0], $pb[0])) {
        return 0.0;
    }
    $usadas = [];
    $iguais = 0;
    foreach ($pa as $x) {
        foreach ($pb as $j => $y) {
            if (!isset($usadas[$j]) && $parecida($x, $y)) {
                $usadas[$j] = true;
                $iguais++;
                break;
            }
        }
    }
    return $iguais / max(count($pa), count($pb));
}

/** Parcelas de uma fórmula de soma simples (=1295+245 -> [129500, 24500]); null se não for desse tipo. */
function parcelasFormula(string $formula): ?array
{
    $f = str_replace(' ', '', ltrim($formula, '='));
    if (!preg_match('/^\d+(\.\d+)?(\+\d+(\.\d+)?)*$/', $f)) {
        return null;
    }
    return array_map(fn ($p) => (int) EmpenhoService::centavos($p), explode('+', $f));
}

/** Comentário "1295 do empenho 748 / 245 no empenho 2658" -> [[129500, '748'], [24500, '2658']]. */
function partesComentario(string $texto): array
{
    preg_match_all('/([\d.,]+)\s*(?:do|no)\s+empenho\s*\/?\s*([\d.,]*)/iu', $texto, $m, PREG_SET_ORDER);
    $partes = [];
    foreach ($m as $p) {
        $partes[] = [(int) EmpenhoService::centavos($p[1]), trim($p[2], ' ,.')];
    }
    return $partes;
}

// ----------------------------------------------------------------------
// Leitura da planilha
// ----------------------------------------------------------------------
$leitor = IOFactory::createReaderForFile($arquivo);
$leitor->setReadDataOnly(false); // precisa dos comentários
$planilha = $leitor->load($arquivo);
$aba = $planilha->getActiveSheet();
$comentarios = [];
foreach ($aba->getComments() as $coord => $comentario) {
    $comentarios[$coord] = $comentario->getText()->getPlainText();
}

/** Valor e fórmula de uma célula (o valor de fórmula é o resultado gravado pelo Excel). */
$ler = function (string $coord) use ($aba): array {
    $celula = $aba->getCell($coord);
    $bruto = $celula->getValue();
    if (is_string($bruto) && str_starts_with($bruto, '=')) {
        return ['valor' => $celula->getOldCalculatedValue(), 'formula' => $bruto];
    }
    return ['valor' => $bruto, 'formula' => null];
};

// Lotações: título da seção na planilha -> nome no cadastro (tabela "locais").
$locaisCadastro = array_map(fn ($l) => (string) $l['Nome'], repositorioDados()->getLocais());
$palavrasLotacao = [
    'PEQUENINOS' => 'Pequeninos',
    'PEQUENO PRINCIPE' => 'Pequeno Príncipe',
    'SOSSEGO' => 'Sossego',
    'CUSTODIO' => 'Custódio',
    'CECILIA' => 'Cecília',
    'SEMED' => 'SEMECE', // SEMECE é a antiga SEMED
];
$lotacaoDoTitulo = function (string $titulo) use ($locaisCadastro, $palavrasLotacao): ?string {
    $t = normalizarNome($titulo);
    foreach ($palavrasLotacao as $palavra => $trecho) {
        if (str_contains($t, $palavra)) {
            foreach ($locaisCadastro as $local) {
                if (str_contains(EmpenhoService::chave($local), EmpenhoService::chave($trecho))) {
                    return $local;
                }
            }
        }
    }
    return null;
};

$MESES = ['JANEIRO', 'FEVEREIRO', 'MARCO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
$ultimaLinha = $aba->getHighestDataRow();
$linhas = [];   // cada linha de voluntário lida
$avisosLeitura = [];
$secao = null;

for ($r = 1; $r <= $ultimaLinha; $r++) {
    $b = trim((string) $aba->getCell("B{$r}")->getValue());

    // Cabeçalho da seção: localiza as colunas pelo texto (B a Z).
    if (normalizarNome($b) === 'EMPENHO') { // "EMPENHO 1" (o número final é removido)
        $titulo = trim((string) $aba->getCell('B' . ($r - 1))->getValue());
        $colunas = ['empenhos' => [], 'meses' => []];
        for ($col = 2; $col <= 26; $col++) {
            $letra = Coordinate::stringFromColumnIndex($col);
            $texto = normalizarNome((string) $aba->getCell("{$letra}{$r}")->getValue());
            $bruto = strtoupper(trim((string) $aba->getCell("{$letra}{$r}")->getValue()));
            if (preg_match('/^EMPENHO (\d)$/', $bruto, $m)) {
                $colunas['empenhos'][(int) $m[1]]['numero'] = $letra;
            } elseif (preg_match('/^VALOR EMPENHO (\d)$/', $bruto, $m)) {
                $colunas['empenhos'][(int) $m[1]]['valor'] = $letra;
            } elseif ($texto === 'NOME') {
                $colunas['nome'] = $letra;
            } elseif (($i = array_search($texto, $MESES, true)) !== false) {
                $colunas['meses'][$i + 1] = $letra;
            } elseif ($texto === 'TOTAL') {
                $colunas[isset($colunas['meses'][12]) ? 'total' : 'total1'] = $letra;
            }
        }
        ksort($colunas['empenhos']);
        $lotacao = $lotacaoDoTitulo($titulo);
        if ($lotacao === null) {
            $avisosLeitura[] = "Linha {$r}: lotação \"{$titulo}\" não encontrada no cadastro de Locais — seção ignorada.";
        }
        $secao = ['titulo' => $titulo, 'lotacao' => $lotacao, 'colunas' => $colunas];
        continue;
    }
    if ($secao === null || $secao['lotacao'] === null) {
        continue;
    }

    $col = $secao['colunas'];
    $nome = trim((string) $aba->getCell($col['nome'] . $r)->getValue());
    if ($nome === '') {
        continue; // linha sem nome (totais soltos, anotações abaixo do quadro): ignorada
    }

    $linha = [
        'linha' => $r, 'nome' => $nome, 'lotacao' => $secao['lotacao'],
        'empenhos' => [], 'meses' => [], 'marcacoes' => [],
        'total' => $ler($col['total'] . $r), 'total1' => isset($col['total1']) ? $ler($col['total1'] . $r) : null,
        'anuladoAA' => stripos((string) $aba->getCell("AA{$r}")->getValue(), 'ANULADO') !== false
            && stripos((string) $aba->getCell("AA{$r}")->getValue(), 'NÃO') === false
            && stripos((string) $aba->getCell("AA{$r}")->getValue(), 'NAO') === false,
    ];
    foreach ($col['empenhos'] as $n => $cols) {
        $numero = trim((string) $aba->getCell($cols['numero'] . $r)->getValue());
        $valor = $ler($cols['valor'] . $r)['valor'];
        $valorC = $c(is_numeric($valor) ? (float) $valor : (string) $valor);
        if ($numero === '' || $numero === '-' || !$valorC) {
            continue;
        }
        $linha['empenhos'][] = ['ordem' => $n, 'numero' => preg_replace('/\.0$/', '', $numero), 'valor' => $valorC,
            'coluna' => $cols['valor']];
    }
    foreach ($col['meses'] as $mes => $letra) {
        $celula = $ler("{$letra}{$r}");
        $valor = $celula['valor'];
        if ($valor === null || $valor === '' || $valor === '-') {
            continue;
        }
        if (!is_numeric($valor)) {
            $linha['marcacoes'][] = "{$letra}{$r}: texto \"{$valor}\" no mês " . ucfirst(strtolower($MESES[$mes - 1])) . ' (não lançado)';
            continue;
        }
        $centavos = (int) $c((float) $valor);
        if ($centavos === 0) {
            continue;
        }
        $linha['meses'][$mes] = [
            'valor' => $centavos, 'coord' => "{$letra}{$r}",
            'parcelas' => $celula['formula'] !== null ? parcelasFormula($celula['formula']) : null,
            'comentario' => $comentarios["{$letra}{$r}"] ?? '',
        ];
    }
    // "− X" fixo na fórmula do TOTAL do 1º semestre = anulação em julho.
    $linha['anulacaoK'] = 0;
    if (!empty($linha['total1']['formula']) && preg_match('/\)\s*-\s*([\d.]+)\s*$/', $linha['total1']['formula'], $m)) {
        $linha['anulacaoK'] = (int) $c($m[1]);
    }
    $linhas[] = $linha;
}

// ----------------------------------------------------------------------
// Ligação com os voluntários cadastrados
// ----------------------------------------------------------------------
$dados = repositorioDados();
$porNome = [];
$voluntarios = [];
foreach ($dados->getVoluntarios() as $v) {
    $voluntarios[(int) $v['ID']] = $v;
    $porNome[normalizarNome((string) $v['Nome'])][] = (int) $v['ID'];
}
$contratoAtual = [];
foreach ($dados->getContratos() as $ct) {
    $idV = (int) $ct['ID Voluntario'];
    if (!isset($contratoAtual[$idV]) || (int) $ct['ID'] > (int) $contratoAtual[$idV]['ID']) {
        $contratoAtual[$idV] = $ct;
    }
}

$mapa = [];
if (is_file($opcoes['mapa'])) {
    $mapa = json_decode((string) file_get_contents($opcoes['mapa']), true);
    if (!is_array($mapa)) {
        fwrite(STDERR, "O arquivo {$opcoes['mapa']} não é um JSON válido.\n");
        exit(1);
    }
}
$mapaNormalizado = [];
foreach ($mapa as $nomePlanilha => $alvo) {
    $mapaNormalizado[normalizarNome((string) $nomePlanilha)] = $alvo;
}

$naoEncontrados = [];
$sugestoesMapa = [];
foreach ($linhas as &$linha) {
    $chave = normalizarNome($linha['nome']);
    $linha['idVoluntario'] = null;
    $linha['como'] = '';
    if (array_key_exists($chave, $mapaNormalizado)) {
        $alvo = $mapaNormalizado[$chave];
        if ($alvo === null || $alvo === '' || $alvo === 0) {
            $linha['como'] = 'pulado pelo mapa';
        } elseif (isset($voluntarios[(int) $alvo])) {
            $linha['idVoluntario'] = (int) $alvo;
            $linha['como'] = 'mapa';
        } else {
            $linha['como'] = "mapa aponta para o ID {$alvo}, que não existe";
        }
    } elseif (count($porNome[$chave] ?? []) === 1) {
        $linha['idVoluntario'] = $porNome[$chave][0];
        $linha['como'] = 'nome igual';
    } elseif (count($porNome[$chave] ?? []) > 1) {
        $linha['como'] = 'nome repetido no cadastro (IDs ' . implode(', ', $porNome[$chave]) . ')';
    }

    if ($linha['idVoluntario'] === null) {
        $melhor = null;
        $nota = 0.0;
        foreach ($voluntarios as $id => $v) {
            $s = semelhancaNome($chave, normalizarNome((string) $v['Nome']));
            if ($s > $nota) {
                [$melhor, $nota] = [$id, $s];
            }
        }
        $sugestao = $melhor !== null && $nota >= 0.5 ? $melhor : null;
        $linha['sugestao'] = $sugestao === null ? null : ['id' => $sugestao, 'nome' => $voluntarios[$sugestao]['Nome'], 'nota' => $nota];
        $naoEncontrados[] = $linha;
        if (!array_key_exists($chave, $mapaNormalizado)) {
            $sugestoesMapa[$linha['nome']] = $sugestao;
        }
    }
}
unset($linha);

// ----------------------------------------------------------------------
// Empenhos e movimentos de cada linha
// ----------------------------------------------------------------------
$planoEmpenhos = [];   // [linha => [ {numero, valor, ...} ]]
$planoMovimentos = []; // [linha => [ {ordem, tipo, competencia, valor, obs} ]]
$relDivisao = ['comentario' => 0, 'formula' => 0, 'automatica' => 0];
$conflitosComentario = [];
$semSaldo = [];
$anulacoes = [];
$diferencas = [];
$numerosVistos = [];
$numerosRepetidos = [];

foreach ($linhas as $linha) {
    $r = $linha['linha'];
    foreach ($linha['empenhos'] as $e) {
        $numerosVistos[$e['numero']][] = $r;
    }
    if ($linha['idVoluntario'] === null) {
        continue;
    }
    $saldos = [];
    foreach ($linha['empenhos'] as $i => $e) {
        $saldos[$i] = $e['valor'];
    }
    $movs = [];
    $debitar = function (int $i, int $valor, string $tipo, int $mes, string $obs) use (&$saldos, &$movs) {
        $saldos[$i] -= $valor;
        $movs[] = ['empenho' => $i, 'tipo' => $tipo, 'mes' => $mes, 'valor' => $valor, 'obs' => $obs];
    };
    /** Do mais antigo com saldo; o que faltar vai para o último empenho (fica negativo e aparece no relatório). */
    $automatico = function (int $valor, string $tipo, int $mes, string $obs) use (&$saldos, $debitar, $linha, &$semSaldo, $br) {
        if (empty($saldos)) {
            $semSaldo[] = "Linha {$linha['linha']} ({$linha['nome']}): " . ucfirst(strtolower($tipo)) . " de R$ {$br($valor)} sem nenhum empenho na linha.";
            return;
        }
        foreach ($saldos as $i => $s) {
            if ($valor <= 0) {
                break;
            }
            if ($s <= 0) {
                continue;
            }
            $parte = min($s, $valor);
            $debitar($i, $parte, $tipo, $mes, $obs);
            $valor -= $parte;
        }
        if ($valor > 0) {
            $ultimo = array_key_last($saldos);
            $debitar($ultimo, $valor, $tipo, $mes, $obs);
            $semSaldo[] = sprintf('Linha %d (%s): %s de %02d/%d passa do saldo dos empenhos em R$ %s (lançado no empenho %s).',
                $linha['linha'], $linha['nome'], strtolower($tipo), $mes, $GLOBALS['ano'], $br($valor), $linha['empenhos'][$ultimo]['numero']);
        }
    };
    $indicePorNumero = [];
    foreach ($linha['empenhos'] as $i => $e) {
        $indicePorNumero[$e['numero']] = $i;
    }

    for ($mes = 1; $mes <= 12; $mes++) {
        // Anulação de julho escondida na fórmula do TOTAL do 1º semestre.
        $anulJulho = $mes === 7 ? $linha['anulacaoK'] : 0;
        $celula = $linha['meses'][$mes] ?? null;

        if ($celula !== null && $celula['valor'] < 0) {
            $anulacoes[] = "Linha {$r} ({$linha['nome']}): anulação de R$ {$br(-$celula['valor'])} em " . sprintf('%02d', $mes) . " ({$celula['coord']}).";
            $automatico(-$celula['valor'], EmpenhoService::ANULACAO, $mes, "Anulação importada da planilha ({$celula['coord']})");
            $celula = null;
        }

        if ($celula !== null) {
            $valor = $celula['valor'];
            $parcelas = $celula['parcelas'];
            // Parte do valor de julho pode ser a própria anulação (ex.: "parte pagamento e parte anulação").
            if ($anulJulho > 0 && $parcelas && in_array($anulJulho, $parcelas, true)) {
                unset($parcelas[array_search($anulJulho, $parcelas, true)]);
                $parcelas = array_values($parcelas);
                $valor -= $anulJulho;
            }

            $feito = false;
            // 1) Comentário
            $partes = partesComentario($celula['comentario']);
            if ($partes) {
                $soma = array_sum(array_column($partes, 0));
                $validos = array_filter($partes, fn ($p) => isset($indicePorNumero[$p[1]]));
                if ($soma === $valor && count($validos) === count($partes)) {
                    foreach ($partes as [$v, $numero]) {
                        $debitar($indicePorNumero[$numero], $v, EmpenhoService::PAGAMENTO, $mes, '');
                    }
                    $relDivisao['comentario']++;
                    $feito = true;
                } else {
                    $conflitosComentario[] = sprintf('%s (%s): comentário "%s" não bate com a linha (%s) — usada a regra automática.',
                        $celula['coord'], $linha['nome'], str_replace("\n", ' / ', trim($celula['comentario'])),
                        $soma !== $valor ? "soma R$ {$br($soma)} ≠ R$ {$br($valor)}" : 'empenho citado não está na linha');
                }
            }
            // 2) Parcelas da fórmula, cada uma inteira num empenho (do mais antigo com saldo que comporte).
            if (!$feito && $parcelas && count($parcelas) > 1 && array_sum($parcelas) === $valor) {
                $simulado = $saldos;
                $destinos = [];
                foreach ($parcelas as $p) {
                    $destino = null;
                    foreach ($simulado as $i => $s) {
                        if ($s >= $p) {
                            $destino = $i;
                            break;
                        }
                    }
                    if ($destino === null) {
                        $destinos = null;
                        break;
                    }
                    $simulado[$destino] -= $p;
                    $destinos[] = [$destino, $p];
                }
                if ($destinos !== null) {
                    foreach ($destinos as [$i, $p]) {
                        $debitar($i, $p, EmpenhoService::PAGAMENTO, $mes, '');
                    }
                    $relDivisao['formula']++;
                    $feito = true;
                }
            }
            // 3) Automática
            if (!$feito && $valor > 0) {
                $automatico($valor, EmpenhoService::PAGAMENTO, $mes, '');
                $relDivisao['automatica']++;
            }
        }

        if ($anulJulho > 0) {
            $anulacoes[] = "Linha {$r} ({$linha['nome']}): anulação de R$ {$br($anulJulho)} em julho (fórmula do TOTAL do 1º semestre).";
            $automatico($anulJulho, EmpenhoService::ANULACAO, 7, 'Anulação em julho (importada da fórmula do TOTAL do 1º semestre)');
        }
    }

    $planoEmpenhos[$r] = $linha['empenhos'];
    $planoMovimentos[$r] = $movs;

    // Comparação com o TOTAL (Z) da planilha
    $saldoCalculado = array_sum($saldos);
    $totalPlanilha = (int) $c(is_numeric($linha['total']['valor']) ? (float) $linha['total']['valor'] : (string) $linha['total']['valor']);
    if ($saldoCalculado !== $totalPlanilha) {
        $diferencas[] = ['linha' => $linha, 'calculado' => $saldoCalculado, 'planilha' => $totalPlanilha,
            'motivo' => explicarDiferenca($linha, $saldoCalculado, $totalPlanilha)];
    }
    if ($linha['anuladoAA'] && $saldoCalculado !== 0) {
        $linha['marcacoes'][] = "Marcada \"JÁ FOI ANULADO\", mas o saldo calculado é R$ {$br($saldoCalculado)}.";
    }
    foreach ($linha['marcacoes'] as $mk) {
        $avisosLeitura[] = "Linha {$r} ({$linha['nome']}): {$mk}";
    }
}
foreach ($numerosVistos as $numero => $ondes) {
    if (count($ondes) > 1) {
        $numerosRepetidos[] = "Empenho nº {$numero} aparece nas linhas " . implode(', ', $ondes) . '.';
    }
}

/** Por que o saldo calculado difere do TOTAL da planilha. */
function explicarDiferenca(array $linha, int $calculado, int $planilha): string
{
    $formula = (string) ($linha['total']['formula'] ?? '');
    $motivos = [];
    if ($formula === '') {
        $motivos[] = 'TOTAL digitado à mão (sem fórmula)';
    } else {
        $f = strtoupper(str_replace('$', '', $formula));
        $r = $linha['linha'];
        // Colunas que a fórmula soma (expande intervalos "F5:J5" e listas).
        $referidas = [];
        preg_match_all('/([A-Z]+)' . $r . '(?::([A-Z]+)' . $r . ')?/', $f, $m, PREG_SET_ORDER);
        foreach ($m as $ref) {
            $ini = Coordinate::columnIndexFromString($ref[1]);
            $fim = isset($ref[2]) && $ref[2] !== '' ? Coordinate::columnIndexFromString($ref[2]) : $ini;
            for ($i = $ini; $i <= $fim; $i++) {
                $referidas[Coordinate::stringFromColumnIndex($i)] = true;
            }
        }
        $semPrimeiroSemestre = false;
        foreach ($linha['empenhos'] as $e) {
            if ($e['ordem'] === 1 && isset($referidas['K'])) {
                continue; // usa o TOTAL do 1º semestre no lugar do empenho 1
            }
            if (!isset($referidas[$e['coluna']])) {
                if ($e['ordem'] === 1) {
                    $semPrimeiroSemestre = true;
                    $saldoK = EmpenhoService::centavos((string) ($linha['total1']['valor'] ?? '')) ?? 0;
                    $motivos[] = "a fórmula {$formula} não conta o empenho 1 (nº {$e['numero']}) nem o 1º semestre: "
                        . 'ficou de fora o saldo de R$ ' . EmpenhoService::br($saldoK) . ' desse empenho (coluna K)';
                } else {
                    $motivos[] = "a fórmula deixa de fora o empenho {$e['numero']}";
                }
            }
        }
        foreach ($linha['meses'] as $mes => $cel) {
            $letra = preg_replace('/\d+$/', '', $cel['coord']);
            $usaK = (isset($referidas['K']) || $semPrimeiroSemestre) && $mes <= 6;
            if (!$usaK && !isset($referidas[$letra])) {
                $motivos[] = 'a fórmula deixa de fora o mês ' . sprintf('%02d', $mes) . " ({$cel['coord']})";
            }
        }
        if (empty($motivos)) {
            $motivos[] = "fórmula {$formula} — o TOTAL do 1º semestre (K) ou outra célula da linha está digitado à mão/inconsistente";
        }
    }
    return implode('; ', $motivos);
}

// ----------------------------------------------------------------------
// Relatório
// ----------------------------------------------------------------------
$linhasImportadas = array_filter($linhas, fn ($l) => $l['idVoluntario'] !== null);
$totalEmp = 0;
$totalMov = 0;
$porLotacao = [];
foreach ($linhas as $l) {
    $lot = &$porLotacao[$l['lotacao']];
    $lot ??= ['linhas' => 0, 'importadas' => 0, 'empenhado' => 0, 'pago' => 0, 'anulado' => 0];
    $lot['linhas']++;
    if ($l['idVoluntario'] !== null) {
        $lot['importadas']++;
        foreach ($planoEmpenhos[$l['linha']] as $e) {
            $lot['empenhado'] += $e['valor'];
            $totalEmp++;
        }
        foreach ($planoMovimentos[$l['linha']] as $mv) {
            $lot[$mv['tipo'] === EmpenhoService::ANULACAO ? 'anulado' : 'pago'] += $mv['valor'];
            $totalMov++;
        }
    }
    unset($lot);
}

$titulo = fn (string $t) => print("\n== {$t} " . str_repeat('=', max(3, 70 - mb_strlen($t))) . "\n");
echo $executar ? "MODO: EXECUTAR (vai gravar)\n" : "MODO: SIMULAÇÃO (nada é gravado)\n";
echo 'Banco: ' . (repositorioDados() instanceof MySQLService ? repositorioDados()->pdo()->query('SELECT DATABASE()')->fetchColumn() : get_class(repositorioDados())) . " · Ano: {$ano}\n";

$titulo('Resumo por lotação');
printf("%-32s %7s %10s %14s %14s %12s\n", 'Lotação', 'Linhas', 'Importadas', 'Empenhado', 'Pago', 'Anulado');
foreach ($porLotacao as $nome => $l) {
    printf("%-32s %7d %10d %14s %14s %12s\n", mb_strimwidth($nome, 0, 32), $l['linhas'], $l['importadas'], $br($l['empenhado']), $br($l['pago']), $br($l['anulado']));
}
printf("\n%d linha(s) lida(s), %d importada(s): %d empenho(s) e %d movimento(s).\n", count($linhas), count($linhasImportadas), $totalEmp, $totalMov);
printf("Divisão dos pagamentos: %d pelo comentário, %d pela fórmula, %d automática.\n", $relDivisao['comentario'], $relDivisao['formula'], $relDivisao['automatica']);

$titulo('Nomes não encontrados no cadastro (linha pulada)');
if (!$naoEncontrados) {
    echo "Nenhum.\n";
}
foreach ($naoEncontrados as $l) {
    $sug = $l['sugestao'] ? sprintf(' -> sugestão: #%d %s (%.0f%%)', $l['sugestao']['id'], $l['sugestao']['nome'], $l['sugestao']['nota'] * 100) : ' -> sem sugestão';
    printf("Linha %3d  %-42s %s%s\n", $l['linha'], $l['nome'], $l['como'] ? "[{$l['como']}]" : '', $sug);
}

$titulo('Diferenças: saldo calculado x TOTAL (Z) da planilha');
if (!$diferencas) {
    echo "Nenhuma: todos os saldos batem.\n";
}
foreach ($diferencas as $d) {
    printf("Linha %3d  %-40s calculado R$ %10s · planilha R$ %10s · dif. R$ %9s\n           motivo: %s\n",
        $d['linha']['linha'], mb_strimwidth($d['linha']['nome'], 0, 40), $br($d['calculado']), $br($d['planilha']),
        $br($d['calculado'] - $d['planilha']), $d['motivo']);
}
printf("\n%d de %d linha(s) importada(s) batem com o TOTAL (Z).\n", count($linhasImportadas) - count($diferencas), count($linhasImportadas));

$titulo('Anulações encontradas');
echo $anulacoes ? implode("\n", $anulacoes) . "\n" : "Nenhuma.\n";
$titulo('Comentários que não batem com a linha');
echo $conflitosComentario ? implode("\n", $conflitosComentario) . "\n" : "Nenhum.\n";
$titulo('Pagamentos acima do saldo dos empenhos');
echo $semSaldo ? implode("\n", $semSaldo) . "\n" : "Nenhum.\n";
$titulo('Outros avisos');
$outros = array_merge($avisosLeitura, $numerosRepetidos);
echo $outros ? implode("\n", $outros) . "\n" : "Nenhum.\n";

// Sugestões para o mapa de nomes (só cria o arquivo se ainda não existir).
if ($sugestoesMapa && !is_file($opcoes['mapa'])) {
    @mkdir(dirname($opcoes['mapa']), 0775, true);
    file_put_contents($opcoes['mapa'], json_encode($sugestoesMapa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\nCriado {$opcoes['mapa']} com as sugestões de nomes: confira os IDs (null = pular a linha) e rode de novo.\n";
}

if (!$executar) {
    echo "\nSimulação concluída: nada foi gravado.\n";
    exit(0);
}

// ----------------------------------------------------------------------
// Gravação
// ----------------------------------------------------------------------
$existentes = array_filter($dados->listar('empenhos'), fn ($e) => (int) $e['Ano'] === $ano);
if ($existentes && empty($flags['substituir'])) {
    fwrite(STDERR, "\n[PARADO] Já existem " . count($existentes) . " empenho(s) de {$ano} no sistema. Use --substituir para apagá-los (com os movimentos) e importar de novo.\n");
    exit(1);
}
$dados->listar('movimentos_empenho'); // garante a tabela antes da transação

$dados->emTransacao(function () use ($dados, $existentes, $linhasImportadas, $planoEmpenhos, $planoMovimentos, $contratoAtual, $ano) {
    $idsApagar = array_map(fn ($e) => (int) $e['ID'], $existentes);
    foreach ($dados->listar('movimentos_empenho') as $m) {
        if (in_array((int) $m['ID Empenho'], $idsApagar, true)) {
            $dados->excluir('movimentos_empenho', (int) $m['ID']);
        }
    }
    foreach ($idsApagar as $id) {
        $dados->excluir('empenhos', $id);
    }

    $agora = date('Y-m-d H:i:s');
    foreach ($linhasImportadas as $l) {
        $idV = $l['idVoluntario'];
        $ids = [];
        foreach ($planoEmpenhos[$l['linha']] as $i => $e) {
            $gravado = $dados->inserir('empenhos', [
                'ID Voluntario' => (string) $idV,
                'ID Contrato' => (string) ($contratoAtual[$idV]['ID'] ?? ''),
                'Ano' => (string) $ano,
                'Numero Empenho' => $e['numero'],
                'Data Empenho' => '',
                'Valor' => EmpenhoService::reais($e['valor']),
                'Tipo' => $e['ordem'] === 1 ? 'ORIGINAL' : 'REFORCO',
                'Lotacao' => $l['lotacao'],
                'Fonte Recurso' => '',
                'Observacao' => "Importado da planilha {$ano} (linha {$l['linha']}, empenho {$e['ordem']})",
                'Data Cadastro' => $agora,
            ]);
            $ids[$i] = (int) $gravado['ID'];
        }
        foreach ($planoMovimentos[$l['linha']] as $mv) {
            $dados->inserir('movimentos_empenho', [
                'ID Empenho' => (string) $ids[$mv['empenho']],
                'ID Voluntario' => (string) $idV,
                'Tipo' => $mv['tipo'],
                'Competencia' => sprintf('%04d-%02d', $ano, $mv['mes']),
                'Valor' => EmpenhoService::reais($mv['valor']),
                'Dias Trabalhados' => '',
                'Data' => '',
                'Observacao' => $mv['obs'] ?: 'Importado da planilha',
                'Data Cadastro' => $agora,
            ]);
        }
    }
});
echo "\nImportação gravada: {$totalEmp} empenho(s) e {$totalMov} movimento(s).\n";
