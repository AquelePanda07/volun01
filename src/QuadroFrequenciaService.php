<?php
/**
 * src/QuadroFrequenciaService.php
 *
 * "Quadro de Frequência": Relatório e Recibo Mensal de Atividades
 * Desenvolvidas por Voluntário (Programa Voluntariado / FNDE).
 *
 * Gera UM documento Word com o relatório de cada voluntário selecionado,
 * um por página, na ordem recebida. Os dias são gerados automaticamente:
 *
 *   - somente os dias da semana do contrato do voluntário (SEG, TER...);
 *     contrato sem dias informados -> segunda a sexta;
 *   - somente dentro do período do contrato (data de início/término);
 *   - opcionalmente só até um dia do mês ("ate_dia");
 *   - feriados/pontos facultativos (ocorrências) continuam listados, com
 *     o texto na coluna "Rubrica", como no modelo original.
 *
 * Valor do recibo e datas de autenticação podem ir vazios: o documento
 * sai com linhas em branco para preenchimento à mão.
 */

declare(strict_types=1);

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as EstiloTabela;

class QuadroFrequenciaService
{
    /** Códigos de dia usados no cadastro (diasSemana), na ordem de date('N') (1 = segunda). */
    private const CODIGOS_DIA = [1 => 'SEG', 2 => 'TER', 3 => 'QUA', 4 => 'QUI', 5 => 'SEX', 6 => 'SAB', 7 => 'DOM'];
    private const NOMES_DIA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
    public const MESES = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /** A4 (210 mm) menos as margens laterais de 10 mm. */
    private const LARGURA_UTIL = 10773;
    private const PASTA_SAIDA = __DIR__ . '/../documentos/frequencia';

    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/frequencia.php';
    }

    // ------------------------------------------------------------------
    // Montagem dos dados (equivalente ao JSON do modelo)
    // ------------------------------------------------------------------

    /**
     * Dias do relatório de um voluntário no mês.
     * @param array<string, string> $ocorrencias 'AAAA-MM-DD' => 'FERIADO'...
     * @return array<int, array{data: string, dia_semana: string, horario: string, descricao: string, observacao: string}>
     */
    public function gerarAtividades(array $v, int $ano, int $mes, string $descricao, array $ocorrencias = [], ?int $ateDia = null): array
    {
        $ultimoDoMes = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $ultimo = $ateDia ? min($ateDia, $ultimoDoMes) : $ultimoDoMes;

        $codigos = array_filter(array_map('trim', explode(',', (string) ($v['diasSemana'] ?? ''))));
        $codigosValidos = array_values(array_intersect($codigos, self::CODIGOS_DIA));
        $diasPermitidos = $codigosValidos ?: ['SEG', 'TER', 'QUA', 'QUI', 'SEX'];

        $inicio = (string) ($v['dataInicio'] ?? '');
        $termino = (string) ($v['dataTermino'] ?? '');
        $horario = $this->formatarHorario((string) ($v['horarioInicio'] ?? ''), (string) ($v['horarioTermino'] ?? ''));

        $lista = [];
        for ($dia = 1; $dia <= $ultimo; $dia++) {
            $iso = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            if (($inicio !== '' && $iso < $inicio) || ($termino !== '' && $iso > $termino)) {
                continue;
            }
            $diaSemana = (int) date('N', strtotime($iso));
            if (!in_array(self::CODIGOS_DIA[$diaSemana], $diasPermitidos, true)) {
                continue;
            }
            $lista[] = [
                'data' => sprintf('%02d/%02d', $dia, $mes),
                'dia_semana' => self::NOMES_DIA[$diaSemana],
                'horario' => $horario,
                'descricao' => $descricao,
                'observacao' => mb_strtoupper(trim((string) ($ocorrencias[$iso] ?? '')), 'UTF-8'),
            ];
        }
        return $lista;
    }

    /**
     * Dados completos do relatório de um voluntário.
     * @param array $opcoes ano, mes, ateDia, ocorrencias, descricao, valor, dataVoluntario, dataCertificacao
     * @param array|null $responsavel ['nome', 'cargo', 'lotacao', 'ato_nomeacao']
     */
    public function montarRelatorio(array $v, array $opcoes, ?array $responsavel): array
    {
        $ano = (int) $opcoes['ano'];
        $mes = (int) $opcoes['mes'];
        $valor = $this->normalizarValor((string) ($opcoes['valor'] ?? ''));

        return [
            'cabecalho' => $this->config['cabecalho'],
            'unidade' => $this->config['unidade'],
            'legislacao' => $this->config['legislacao'],
            'periodo' => ['mes' => $mes, 'mes_extenso' => mb_strtoupper(self::MESES[$mes], 'UTF-8'), 'ano' => $ano],
            'voluntario' => [
                'nome' => mb_strtoupper(trim((string) ($v['nome'] ?? '')), 'UTF-8'),
                'cpf' => (string) ($v['cpf'] ?? ''),
                'endereco' => trim(($v['logradouro'] ?? '') . (!empty($v['numero']) ? ', ' . $v['numero'] : '')),
                'telefone' => (string) ($v['contato'] ?? ''),
            ],
            'atividades' => $this->gerarAtividades(
                $v, $ano, $mes, (string) $opcoes['descricao'],
                (array) ($opcoes['ocorrencias'] ?? []), !empty($opcoes['ateDia']) ? (int) $opcoes['ateDia'] : null
            ),
            'recibo' => [
                'valor' => $valor === null ? '' : number_format($valor, 2, ',', '.'),
                'valor_extenso' => $valor === null ? '' : self::valorPorExtenso($valor),
            ],
            'autenticacao' => [
                'data_voluntario' => $this->dataPorExtenso((string) ($opcoes['dataVoluntario'] ?? '')),
                'data_certificacao' => $this->dataPorExtenso((string) ($opcoes['dataCertificacao'] ?? '')),
            ],
            'responsavel' => $responsavel ?? ['nome' => '', 'cargo' => '', 'lotacao' => '', 'ato_nomeacao' => ''],
        ];
    }

    // ------------------------------------------------------------------
    // Geração do Word (um relatório por página)
    // ------------------------------------------------------------------

    /**
     * @param array<int, array> $relatorios saída de montarRelatorio(), na ordem desejada
     * @return array{caminho: string, nomeArquivo: string}
     */
    public function gerarDocx(array $relatorios, int $ano, int $mes): array
    {
        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.0]);
        $word->getDocInfo()->setTitle(sprintf('Quadro de Frequência - %s/%d', mb_strtoupper(self::MESES[$mes], 'UTF-8'), $ano));

        foreach ($relatorios as $r) {
            $secao = $word->addSection([
                'paperSize' => 'A4',
                'marginTop' => Converter::cmToTwip(1.0),
                'marginBottom' => Converter::cmToTwip(0.9),
                'marginLeft' => Converter::cmToTwip(1.0),
                'marginRight' => Converter::cmToTwip(1.0),
            ]);
            $this->escreverRelatorio($secao, $r);
        }

        if (!is_dir(self::PASTA_SAIDA)) {
            mkdir(self::PASTA_SAIDA, 0775, true);
        }
        $nome = sprintf('Quadro_Frequencia_%04d_%02d_%s_%s.docx', $ano, $mes, date('Ymd_His'), bin2hex(random_bytes(3)));
        $caminho = self::PASTA_SAIDA . '/' . $nome;
        $word->save($caminho, 'Word2007');
        $this->limparArquivosAntigos();

        return ['caminho' => $caminho, 'nomeArquivo' => $nome];
    }

    public static function caminhoArquivoGerado(string $nome): ?string
    {
        $nome = basename($nome);
        $caminho = self::PASTA_SAIDA . '/' . $nome;
        return preg_match('/^Quadro_Frequencia_\d{4}_\d{2}_\d{8}_\d{6}_[a-f0-9]{6}\.docx$/', $nome) && is_file($caminho) ? $caminho : null;
    }

    private function escreverRelatorio($secao, array $r): void
    {
        $centro = ['alignment' => Jc::CENTER];
        $cab = $r['cabecalho'];

        $logo = $cab['logo'] !== '' ? __DIR__ . '/../' . ltrim($cab['logo'], '/') : '';
        if ($logo !== '' && is_file($logo)) {
            $secao->addImage($logo, ['height' => 45, 'alignment' => Jc::CENTER]);
        }
        $secao->addText($cab['orgao'], ['bold' => true, 'size' => 12], $centro + ['spaceBefore' => 60]);
        $secao->addText($cab['programa'], ['size' => 11], $centro + ['spaceBefore' => 20]);
        $secao->addText('RELATÓRIO E RECIBO MENSAL DE ATIVIDADES DESENVOLVIDAS POR VOLUNTÁRIO', ['bold' => true, 'size' => 11], $centro + ['spaceBefore' => 60, 'spaceAfter' => 80]);

        // Identificação da unidade executora
        $u = $r['unidade'];
        $p = $r['periodo'];
        $t = $this->novaTabela($secao);
        $this->linhaSecao($t, 'IDENTIFICAÇÃO DA UNIDADE EXECUTORA', 5);
        $t->addRow(null, ['cantSplit' => true]);
        $this->celulaRotulo($t, 38, 'Razão Social', $u['razao_social']);
        $this->celulaRotulo($t, 20, 'CNPJ', $u['cnpj']);
        $this->celulaRotulo($t, 18, 'Município', $u['municipio']);
        $this->celulaRotulo($t, 6, 'UF', $u['uf']);
        $this->celulaRotulo($t, 18, 'Mês/Ano', $p['mes_extenso'] . '/' . $p['ano']);
        $this->espaco($secao);

        // Identificação do voluntário
        $v = $r['voluntario'];
        $t = $this->novaTabela($secao);
        $this->linhaSecao($t, 'IDENTIFICAÇÃO DO VOLUNTÁRIO', 4);
        $t->addRow(null, ['cantSplit' => true]);
        $this->celulaRotulo($t, 32, 'Nome', $v['nome'], true);
        $this->celulaRotulo($t, 18, 'CPF', $v['cpf']);
        $this->celulaRotulo($t, 32, 'Endereço', $v['endereco']);
        $this->celulaRotulo($t, 18, 'Telefone', $v['telefone']);
        $this->espaco($secao);

        // Trabalho realizado (cabeçalho da tabela se repete se passar de uma página).
        // Linhas sem margem vertical: até 23 dias úteis cabem na mesma página.
        $larguras = [9, 10, 17, 48, 16];
        $t = $this->novaTabela($secao, 0);
        $this->linhaSecao($t, 'TRABALHO REALIZADO', 5, true);
        $t->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach (['Data do Mês', 'Dia da Semana', 'Horário', 'Atividades Realizadas – Turma', 'Rubrica'] as $i => $titulo) {
            $t->addCell($this->pct($larguras[$i]), ['bgColor' => 'F2F2F2', 'valign' => 'center'])
                ->addText($titulo, ['bold' => true, 'size' => 9], $centro);
        }
        foreach ($r['atividades'] as $item) {
            $t->addRow(null, ['cantSplit' => true]);
            $t->addCell($this->pct($larguras[0]), ['valign' => 'center'])->addText($item['data'], ['size' => 9], $centro);
            $t->addCell($this->pct($larguras[1]), ['valign' => 'center'])->addText($item['dia_semana'], ['size' => 9], $centro);
            $t->addCell($this->pct($larguras[2]), ['valign' => 'center'])->addText($item['horario'], ['size' => 9], $centro);
            $t->addCell($this->pct($larguras[3]), ['valign' => 'center'])->addText($item['descricao'], ['size' => 9]);
            $t->addCell($this->pct($larguras[4]), ['valign' => 'center'])->addText($item['observacao'], ['bold' => true, 'size' => 7], $centro);
        }
        $this->espaco($secao);

        // Recibo
        $rec = $r['recibo'];
        $leg = $r['legislacao'];
        $t = $this->novaTabela($secao);
        $t->addRow(null, ['cantSplit' => true]);
        $texto = $t->addCell(self::LARGURA_UTIL)->addTextRun(['alignment' => Jc::BOTH, 'lineHeight' => 1.3, 'spaceBefore' => 40, 'spaceAfter' => 40]);
        $texto->addText('Recebi da Unidade de Ensino, identificada, a importância de R$ ');
        $texto->addText($rec['valor'] !== '' ? $rec['valor'] : '__________', $rec['valor'] !== '' ? ['bold' => true] : []);
        $texto->addText(' (' . ($rec['valor_extenso'] !== '' ? $rec['valor_extenso'] : str_repeat('_', 46)) . '), ');
        $texto->addText('a título de ressarcimento de despesas com transporte e alimentação referente à realização de serviço voluntário em atividades voltadas ao ');
        $texto->addText($cab['programa_nome'], ['bold' => true]);
        $texto->addText(", em escolas públicas, definidas conforme a {$leg['lei']}, que dispõe sobre os procedimentos e as formas de execução e prestação de contas conforme o {$leg['decreto']}.");
        $this->espaco($secao);

        // Autenticação
        $aut = $r['autenticacao'];
        $resp = $r['responsavel'];
        $local = $u['municipio'] . '/' . $u['uf'] . ', ';
        $emBranco = '____ de ______________ de ' . $p['ano'];
        $t = $this->novaTabela($secao);
        $this->linhaSecao($t, 'AUTENTICAÇÃO', 2);

        $t->addRow(null, ['cantSplit' => true]);
        $t->addCell($this->pct(45), ['valign' => 'bottom'])->addText($local . ($aut['data_voluntario'] ?: $emBranco) . '.');
        $assinatura = $t->addCell($this->pct(55), ['valign' => 'bottom']);
        $this->linhaAssinatura($assinatura);
        $assinatura->addText('Assinatura do Voluntário', ['size' => 9], $centro + ['spaceAfter' => 40]);

        $t->addRow(null, ['cantSplit' => true]);
        $t->addCell(self::LARGURA_UTIL, ['gridSpan' => 2])
            ->addText('Certifico que as atividades foram realizadas nos termos relatados e de forma satisfatória.', [], ['spaceBefore' => 40, 'spaceAfter' => 40]);

        $t->addRow(null, ['cantSplit' => true]);
        $t->addCell($this->pct(45), ['valign' => 'bottom'])->addText($local . ($aut['data_certificacao'] ?: $emBranco) . '.');
        $assinatura = $t->addCell($this->pct(55), ['valign' => 'bottom']);
        $this->linhaAssinatura($assinatura);
        $assinatura->addText($resp['nome'], ['bold' => true, 'size' => 9], $centro);
        if ($resp['cargo'] !== '') {
            $assinatura->addText($resp['cargo'], ['size' => 9], $centro);
        }
        if ($resp['lotacao'] !== '') {
            $assinatura->addText($resp['lotacao'], ['bold' => true, 'size' => 9], $centro);
        }
        if ($resp['ato_nomeacao'] !== '') {
            $assinatura->addText($resp['ato_nomeacao'], ['size' => 9], $centro);
        }
        $assinatura->addText('', ['size' => 4], ['spaceAfter' => 40]);

        // O Word exige um parágrafo depois da última tabela; mínimo, para não
        // empurrar uma página em branco quando o relatório ocupa a página inteira.
        $secao->addText('', ['size' => 1], ['spaceBefore' => 0, 'spaceAfter' => 0, 'spacing' => 20, 'spacingLineRule' => 'exact']);
    }

    private function novaTabela($secao, int $margemVertical = 15)
    {
        return $secao->addTable([
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMarginLeft' => 80,
            'cellMarginRight' => 80,
            'cellMarginTop' => $margemVertical,
            'cellMarginBottom' => $margemVertical,
            'layout' => EstiloTabela::LAYOUT_FIXED,
            'unit' => TblWidth::TWIP,
            'width' => self::LARGURA_UTIL,
        ]);
    }

    private function linhaSecao($tabela, string $titulo, int $colunas, bool $repetir = false): void
    {
        $tabela->addRow(null, ['tblHeader' => $repetir, 'cantSplit' => true]);
        // keepNext: o título nunca fica sozinho no fim da página, separado do conteúdo.
        $tabela->addCell(self::LARGURA_UTIL, ['gridSpan' => $colunas, 'bgColor' => 'D9D9D9'])
            ->addText($titulo, ['bold' => true], ['alignment' => Jc::CENTER, 'keepNext' => true]);
    }

    private function celulaRotulo($tabela, int $percentual, string $rotulo, string $valor, bool $negrito = false): void
    {
        $celula = $tabela->addCell($this->pct($percentual));
        $celula->addText($rotulo, ['size' => 7.5, 'color' => '333333']);
        $celula->addText($valor, $negrito ? ['bold' => true] : []);
    }

    private function linhaAssinatura($celula): void
    {
        $celula->addText('', [], ['spaceBefore' => 300]);
        $celula->addText('_______________________________________________', ['size' => 9], ['alignment' => Jc::CENTER]);
    }

    private function espaco($secao): void
    {
        $secao->addText('', ['size' => 3]);
    }

    private function pct(int $percentual): int
    {
        return (int) round(self::LARGURA_UTIL * $percentual / 100);
    }

    /** Mantém só os arquivos gerados nos últimos 7 dias. */
    private function limparArquivosAntigos(): void
    {
        foreach (glob(self::PASTA_SAIDA . '/Quadro_Frequencia_*.docx') ?: [] as $arquivo) {
            if (filemtime($arquivo) < time() - 7 * 86400) {
                @unlink($arquivo);
            }
        }
    }

    // ------------------------------------------------------------------
    // Formatação
    // ------------------------------------------------------------------

    private function formatarHorario(string $inicio, string $termino): string
    {
        $inicio = substr(trim($inicio), 0, 5);
        $termino = substr(trim($termino), 0, 5);
        if ($inicio === '' && $termino === '') {
            return '';
        }
        return ($inicio ?: '__:__') . ' às ' . ($termino ?: '__:__');
    }

    /** '2026-10-30' -> '30 de outubro de 2026' (vazio/ inválido -> ''). */
    private function dataPorExtenso(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        return sprintf('%02d de %s de %d', (int) $m[3], self::MESES[(int) $m[2]], (int) $m[1]);
    }

    /** '1.234,56' / '150' / '150.5' -> float; vazio ou inválido -> null. */
    public function normalizarValor(string $valor): ?float
    {
        $valor = trim(str_replace(['R$', ' '], '', $valor));
        if ($valor === '') {
            return null;
        }
        if (str_contains($valor, ',')) {
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        }
        return is_numeric($valor) && (float) $valor > 0 ? round((float) $valor, 2) : null;
    }

    /** 150.00 -> 'cento e cinquenta reais'; 1234.56 -> 'mil duzentos e trinta e quatro reais e cinquenta e seis centavos'. */
    public static function valorPorExtenso(float $valor): string
    {
        $reais = (int) floor($valor + 0.0000001);
        $centavos = (int) round(($valor - $reais) * 100);
        if ($centavos === 100) {
            $reais++;
            $centavos = 0;
        }

        $partes = [];
        if ($reais > 0) {
            $exatoEmMilhao = $reais >= 1000000 && $reais % 1000000 === 0;
            $partes[] = self::numeroPorExtenso($reais) . ($exatoEmMilhao ? ' de' : '') . ($reais === 1 ? ' real' : ' reais');
        }
        if ($centavos > 0) {
            $partes[] = self::numeroPorExtenso($centavos) . ($centavos === 1 ? ' centavo' : ' centavos');
        }
        return $partes ? implode(' e ', $partes) : 'zero real';
    }

    private static function numeroPorExtenso(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $grupos = []; // [texto, valor do grupo]
        $milhoes = intdiv($n, 1000000);
        $milhares = intdiv($n % 1000000, 1000);
        $resto = $n % 1000;

        if ($milhoes > 0) {
            $grupos[] = [self::ate999($milhoes) . ($milhoes === 1 ? ' milhão' : ' milhões'), $milhoes];
        }
        if ($milhares > 0) {
            $grupos[] = [$milhares === 1 ? 'mil' : self::ate999($milhares) . ' mil', $milhares];
        }
        if ($resto > 0) {
            $grupos[] = [self::ate999($resto), $resto];
        }

        // O último grupo leva "e" quando é menor que 100 ou centena redonda:
        // "mil e duzentos", "um milhão e quinhentos mil", mas "mil duzentos e trinta".
        $texto = $grupos[0][0];
        for ($i = 1, $total = count($grupos); $i < $total; $i++) {
            [$grupo, $valor] = $grupos[$i];
            $usaE = $i === $total - 1 && ($valor < 100 || $valor % 100 === 0);
            $texto .= ($usaE ? ' e ' : ' ') . $grupo;
        }
        return $texto;
    }

    private static function ate999(int $n): string
    {
        $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez',
            'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
        $dezenas = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
        $centenas = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

        if ($n === 100) {
            return 'cem';
        }
        $partes = [];
        if ($n >= 100) {
            $partes[] = $centenas[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 20) {
            $partes[] = $dezenas[intdiv($n, 10)];
            $n %= 10;
        }
        if ($n > 0) {
            $partes[] = $unidades[$n];
        }
        return implode(' e ', $partes);
    }
}
