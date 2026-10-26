<?php
/**
 * scripts/importar_planilha_antiga.php
 *
 * Importa voluntários (e seus contratos) de uma planilha de outro sistema
 * que já esteja no MESMO formato deste (abas Voluntarios e Contratos, com
 * os mesmos cabeçalhos), convertida para JSON {aba: [ {coluna: valor} ]}.
 *
 * Regras:
 *   - Voluntário cujo CPF (ou nome) já existe no sistema é PULADO: os dados
 *     atuais não são alterados.
 *   - Mantém o ID original do voluntário, se estiver livre (facilita
 *     conferir com a aba "Pendencias" da planilha); senão, usa o próximo.
 *   - Foto não é importada (o caminho aponta para a pasta do formulário
 *     antigo, que não existe aqui).
 *   - Contrato sem data de término recebe a data de ONTEM (fica Encerrado).
 *   - Contrato sem nenhuma data é descartado quando o voluntário tem outro
 *     contrato com datas (linha duplicada).
 *   - Cargos e locais que faltarem nas listas do sistema são acrescentados.
 *
 * Uso:
 *     php scripts/importar_planilha_antiga.php dados.json             # simulação: só mostra o que faria
 *     php scripts/importar_planilha_antiga.php dados.json --executar  # grava
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

$arquivo = $argv[1] ?? '';
$executar = in_array('--executar', $argv, true);
if ($arquivo === '' || !is_file($arquivo)) {
    fwrite(STDERR, "Uso: php scripts/importar_planilha_antiga.php dados.json [--executar]\n");
    exit(1);
}

$planilha = json_decode((string) file_get_contents($arquivo), true);
if (!isset($planilha['Voluntarios'], $planilha['Contratos'])) {
    fwrite(STDERR, "O JSON precisa ter as abas \"Voluntarios\" e \"Contratos\".\n");
    exit(1);
}

$dados = repositorioDados();
$ontem = date('Y-m-d', strtotime('-1 day'));
$digitos = fn ($v) => preg_replace('/\D/', '', (string) $v);
$normalizarNome = function (string $nome): string {
    $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($nome), 'UTF-8')) ?: $nome;
    return preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', '', $semAcento));
};

$gravacoes = 0;
$gravar = function (callable $acao) use ($executar, &$gravacoes) {
    if (!$executar) {
        return null;
    }
    if ($gravacoes > 0) {
        usleep(1100000); // ~55 gravações/minuto: abaixo do limite da API do Google
    }
    $gravacoes++;
    return $acao();
};

// ------------------------------------------------------------------
// Situação atual do sistema
// ------------------------------------------------------------------
$atuais = $dados->getVoluntarios();
$cpfsAtuais = [];
$nomesAtuais = [];
$idsUsados = [];
foreach ($atuais as $v) {
    if ($digitos($v['CPF'] ?? '') !== '') {
        $cpfsAtuais[$digitos($v['CPF'])] = $v;
    }
    $nomesAtuais[$normalizarNome((string) ($v['Nome'] ?? ''))] = $v;
    $idsUsados[(int) $v['ID']] = true;
}
$proximoIdVoluntario = max(array_keys($idsUsados) ?: [0]) + 1;
$proximoIdContrato = max(array_map(fn ($c) => (int) $c['ID'], $dados->getContratos()) ?: [0]) + 1;

$contratosPorVoluntario = [];
foreach ($planilha['Contratos'] as $c) {
    $contratosPorVoluntario[(string) $c['ID Voluntario']][] = $c;
}

echo ($executar ? '== IMPORTAÇÃO ==' : '== SIMULAÇÃO (nada será gravado; use --executar para gravar) ==') . "\n";
echo 'Voluntários no sistema: ' . count($atuais) . ' | na planilha: ' . count($planilha['Voluntarios']) . "\n\n";

// ------------------------------------------------------------------
// Planejamento
// ------------------------------------------------------------------
$colunasVoluntario = (require __DIR__ . '/../config/google-sheets.php')['colunas']['Voluntarios'];
$colunasContrato = (require __DIR__ . '/../config/google-sheets.php')['colunas']['Contratos'];
$ajusteEscolaridade = ['pós-graduação' => 'Pós-graduação', 'pos-graduacao' => 'Pós-graduação'];

$pulados = [];
$plano = []; // [voluntario, contratos[], avisos[]]
$cargosUsados = [];
$locaisUsados = [];

foreach ($planilha['Voluntarios'] as $v) {
    $nome = trim((string) $v['Nome']);
    $cpf = $digitos($v['CPF'] ?? '');
    $existente = ($cpf !== '' ? ($cpfsAtuais[$cpf] ?? null) : null) ?? ($nomesAtuais[$normalizarNome($nome)] ?? null);
    if ($existente !== null) {
        $pulados[] = sprintf('%s (já existe como ID %s)', $nome, $existente['ID']);
        continue;
    }

    $avisos = [];
    $idOriginal = (int) $v['ID'];
    $id = ($idOriginal > 0 && !isset($idsUsados[$idOriginal])) ? $idOriginal : $proximoIdVoluntario++;
    $idsUsados[$id] = true;
    if ($id !== $idOriginal) {
        $avisos[] = "ID {$idOriginal} já usado; importado como {$id}";
    }

    $linha = [];
    foreach ($colunasVoluntario as $coluna) {
        $linha[$coluna] = trim((string) ($v[$coluna] ?? ''));
    }
    $linha['ID'] = (string) $id;
    $linha['Foto'] = '';
    $escolaridade = mb_strtolower($linha['Escolaridade'], 'UTF-8');
    if (isset($ajusteEscolaridade[$escolaridade])) {
        $linha['Escolaridade'] = $ajusteEscolaridade[$escolaridade];
    }
    if ($linha['Data Cadastro'] === '') {
        $linha['Data Cadastro'] = date('Y-m-d H:i:s');
    }
    if ($cpf === '') {
        $avisos[] = 'CPF não informado';
    } elseif (!Validator::validarCPF($linha['CPF'])) {
        $avisos[] = "CPF inválido ({$linha['CPF']})";
    }

    // Contratos (na ordem original: o último fica sendo o vigente)
    $contratosOriginais = $contratosPorVoluntario[(string) $v['ID']] ?? [];
    usort($contratosOriginais, fn ($a, $b) => (int) $a['ID'] <=> (int) $b['ID']);
    $temContratoComData = (bool) array_filter($contratosOriginais, fn ($c) => trim((string) $c['Data Inicio']) !== '' || trim((string) $c['Data Termino']) !== '');
    $contratos = [];
    foreach ($contratosOriginais as $c) {
        $inicio = trim((string) $c['Data Inicio']);
        $termino = trim((string) $c['Data Termino']);
        if ($inicio === '' && $termino === '' && $temContratoComData) {
            $avisos[] = "contrato {$c['ID']} sem datas descartado (duplicado)";
            continue;
        }
        $contrato = [];
        foreach ($colunasContrato as $coluna) {
            $contrato[$coluna] = trim((string) ($c[$coluna] ?? ''));
        }
        // Renovação e termos assinados começam do zero neste sistema.
        foreach (['Renovado', 'Data Renovacao', 'Termino Anterior', 'Anexo Termo', 'Termo Assinado', 'Data Assinatura Termo',
            'Anexo Renovacao', 'Renovacao Assinada', 'Data Assinatura Renovacao'] as $coluna) {
            $contrato[$coluna] = '';
        }
        if ($termino === '') {
            $contrato['Data Termino'] = $ontem;
            $avisos[] = 'término não informado -> ' . $ontem . ($inicio === '' ? ' (início também não informado)' : '');
        }
        $contrato['ID'] = (string) $proximoIdContrato++;
        $contrato['ID Voluntario'] = (string) $id;
        $contrato['Status'] = ContratoService::calcularStatus($contrato['Data Inicio'], $contrato['Data Termino']);
        if ($contrato['Data Cadastro'] === '') {
            $contrato['Data Cadastro'] = $linha['Data Cadastro'];
        }
        $contratos[] = $contrato;
        if ($contrato['Cargo'] !== '') {
            $cargosUsados[$contrato['Cargo']] = true;
        }
        if ($contrato['Local Prestacao'] !== '') {
            $locaisUsados[$contrato['Local Prestacao']] = true;
        }
    }
    if (empty($contratos)) {
        $avisos[] = 'sem contrato';
    }
    $plano[] = [$linha, $contratos, $avisos];
}

// Cargos e locais que faltam nas listas do sistema
$nomesExistentes = fn (string $entidade) => array_map(fn ($r) => mb_strtolower(trim((string) $r['Nome']), 'UTF-8'), $dados->listar($entidade));
$cargosFaltando = array_values(array_filter(array_keys($cargosUsados), fn ($c) => !in_array(mb_strtolower($c, 'UTF-8'), $nomesExistentes('cargos'), true)));
$locaisFaltando = array_values(array_filter(array_keys($locaisUsados), fn ($l) => !in_array(mb_strtolower($l, 'UTF-8'), $nomesExistentes('locais'), true)));

// ------------------------------------------------------------------
// Relatório do plano
// ------------------------------------------------------------------
$totalContratos = array_sum(array_map(fn ($p) => count($p[1]), $plano));
echo 'Pulados (já existem): ' . count($pulados) . "\n";
foreach ($pulados as $p) {
    echo "  - {$p}\n";
}
echo "\nA importar: " . count($plano) . " voluntário(s), {$totalContratos} contrato(s)\n";
echo 'Cargos a acrescentar: ' . ($cargosFaltando ? implode('; ', $cargosFaltando) : 'nenhum') . "\n";
echo 'Locais a acrescentar: ' . ($locaisFaltando ? implode('; ', $locaisFaltando) : 'nenhum') . "\n\n";
$comAviso = array_filter($plano, fn ($p) => !empty($p[2]));
echo 'Com observações (' . count($comAviso) . "):\n";
foreach ($comAviso as [$v, , $avisos]) {
    echo "  - [{$v['ID']}] {$v['Nome']}: " . implode('; ', $avisos) . "\n";
}

if (!$executar) {
    echo "\nSimulação concluída. Nada foi gravado.\n";
    exit(0);
}

// ------------------------------------------------------------------
// Gravação
// ------------------------------------------------------------------
echo "\nGravando (cerca de " . (int) ceil((count($plano) + $totalContratos + count($cargosFaltando) + count($locaisFaltando)) * 1.1 / 60) . " min)...\n";
foreach ($cargosFaltando as $cargo) {
    $gravar(fn () => $dados->inserir('cargos', ['Nome' => $cargo, 'Ativo' => 'TRUE']));
}
foreach ($locaisFaltando as $local) {
    $gravar(fn () => $dados->inserir('locais', ['Nome' => $local, 'Ativo' => 'TRUE']));
}

$feitos = 0;
foreach ($plano as [$voluntario, $contratos]) {
    $gravar(fn () => $dados->createVoluntario($voluntario));
    foreach ($contratos as $contrato) {
        $gravar(fn () => $dados->createContrato($contrato));
    }
    $feitos++;
    if ($feitos % 10 === 0 || $feitos === count($plano)) {
        echo "  {$feitos}/" . count($plano) . " voluntários\n";
    }
}

registrarLog(sprintf('Importação da planilha antiga: %d voluntário(s), %d contrato(s), %d pulado(s).', count($plano), $totalContratos, count($pulados)));
echo "\nConcluído: " . count($plano) . " voluntário(s) e {$totalContratos} contrato(s) importados.\n";
