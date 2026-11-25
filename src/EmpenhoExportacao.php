<?php
/**
 * src/EmpenhoExportacao.php
 * Exporta o painel anual do Controle de Empenhos (EmpenhoService::resumoAnual())
 * em .xlsx (PhpSpreadsheet) ou CSV (abre no Excel).
 *
 * Cada mês mostra o valor líquido do mês (pagamentos − anulações): a anulação
 * aparece negativa, como na planilha antiga.
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmpenhoExportacao
{
    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    private const SITUACAO = ['ANULADO' => 'JÁ FOI ANULADO', 'SALDO_A_ANULAR' => 'SALDO A ANULAR'];

    public function __construct(private array $resumo)
    {
    }

    /** Cabeçalho das colunas (igual nos dois formatos). */
    private function cabecalho(): array
    {
        return array_merge(['Voluntário', 'Empenhos (nº / valor)'], self::MESES, ['Total empenhado', 'Total pago', 'Anulado', 'Saldo', 'Situação']);
    }

    /** Linha de valores de um voluntário ou de um total (centavos -> float). */
    private function valores(array $linha): array
    {
        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $liquido = EmpenhoService::centavos($linha['meses'][$m]['pago']) - EmpenhoService::centavos($linha['meses'][$m]['anulado']);
            $meses[] = $liquido === 0 ? null : $liquido / 100;
        }
        return array_merge($meses, [
            EmpenhoService::centavos($linha['empenhado']) / 100,
            EmpenhoService::centavos($linha['pago']) / 100,
            EmpenhoService::centavos($linha['anulado']) / 100,
            EmpenhoService::centavos($linha['saldo']) / 100,
        ]);
    }

    private function textoEmpenhos(array $linha): string
    {
        return implode("\n", array_map(
            fn ($e) => $e['numero'] . ' / ' . EmpenhoService::br(EmpenhoService::centavos($e['valor'])),
            $linha['empenhos']
        ));
    }

    private function nomeArquivo(string $extensao): string
    {
        return 'Controle_Empenhos_' . $this->resumo['ano'] . '.' . $extensao;
    }

    public function enviarXlsx(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Empenhos ' . $this->resumo['ano']);
        $ultimaColuna = count($this->cabecalho());
        $letra = fn (int $c) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $fim = $letra($ultimaColuna);

        $aba->setCellValue('A1', 'CONTROLE DE EMPENHOS DOS VOLUNTÁRIOS — ' . $this->resumo['ano']);
        $aba->mergeCells("A1:{$fim}1");
        $aba->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $linhaAtual = 3;

        $escreverValores = function (int $linhaPlanilha, array $valores) use ($aba) {
            foreach ($valores as $i => $v) {
                if ($v !== null) {
                    $aba->setCellValue([3 + $i, $linhaPlanilha], $v);
                }
            }
        };

        foreach ($this->resumo['grupos'] as $grupo) {
            $aba->setCellValue("A{$linhaAtual}", mb_strtoupper($grupo['lotacao'], 'UTF-8'));
            $aba->mergeCells("A{$linhaAtual}:{$fim}{$linhaAtual}");
            $aba->getStyle("A{$linhaAtual}")->getFont()->setBold(true);
            $aba->getStyle("A{$linhaAtual}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E2F3');
            $linhaAtual++;

            $aba->fromArray($this->cabecalho(), null, "A{$linhaAtual}");
            $aba->getStyle("A{$linhaAtual}:{$fim}{$linhaAtual}")->getFont()->setBold(true);
            $aba->getStyle("A{$linhaAtual}:{$fim}{$linhaAtual}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDEDED');
            $inicioDados = ++$linhaAtual;

            foreach ($grupo['linhas'] as $linha) {
                $aba->setCellValue("A{$linhaAtual}", $linha['nome']);
                $aba->setCellValue("B{$linhaAtual}", $this->textoEmpenhos($linha));
                $aba->getStyle("B{$linhaAtual}")->getAlignment()->setWrapText(true);
                $escreverValores($linhaAtual, $this->valores($linha));
                $aba->setCellValue([$ultimaColuna, $linhaAtual], self::SITUACAO[$linha['situacao']] ?? '');
                if ($linha['situacao'] !== '') {
                    $cor = $linha['situacao'] === 'ANULADO' ? 'F4CCCC' : 'FFF2CC';
                    $aba->getStyle("A{$linhaAtual}:{$fim}{$linhaAtual}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($cor);
                }
                for ($m = 1; $m <= 12; $m++) {
                    if (EmpenhoService::centavos($linha['meses'][$m]['anulado']) > 0) {
                        $aba->getStyle([2 + $m, $linhaAtual])->getFont()->getColor()->setRGB('C00000');
                    }
                }
                $linhaAtual++;
            }

            $aba->setCellValue("A{$linhaAtual}", 'TOTAL ' . mb_strtoupper($grupo['lotacao'], 'UTF-8'));
            $escreverValores($linhaAtual, $this->valores($grupo['total']));
            $aba->getStyle("A{$linhaAtual}:{$fim}{$linhaAtual}")->getFont()->setBold(true);
            $aba->getStyle("A{$linhaAtual}:{$fim}{$linhaAtual}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $aba->getStyle("C{$inicioDados}:" . $letra($ultimaColuna - 1) . $linhaAtual)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
            $linhaAtual += 2;
        }

        $aba->setCellValue("A{$linhaAtual}", 'TOTAL GERAL');
        $escreverValores($linhaAtual, $this->valores($this->resumo['total']));
        $aba->getStyle("A{$linhaAtual}:{$fim}{$linhaAtual}")->getFont()->setBold(true);
        $aba->getStyle("C{$linhaAtual}:" . $letra($ultimaColuna - 1) . $linhaAtual)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');

        $aba->getColumnDimension('A')->setWidth(42);
        $aba->getColumnDimension('B')->setWidth(20);
        for ($c = 3; $c < $ultimaColuna; $c++) {
            $aba->getColumnDimension($letra($c))->setWidth($c >= 15 ? 14 : 11);
        }
        $aba->getColumnDimension($fim)->setWidth(18);
        $aba->getStyle("A3:{$fim}{$linhaAtual}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $aba->freezePane('C3');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $this->nomeArquivo('xlsx') . '"');
        header('Cache-Control: no-store');
        (new Xlsx($planilha))->save('php://output');
    }

    public function enviarCsv(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->nomeArquivo('csv') . '"');
        header('Cache-Control: no-store');
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF"); // BOM: o Excel reconhece os acentos
        $formatar = fn ($v) => $v === null ? '' : number_format((float) $v, 2, ',', '');

        foreach ($this->resumo['grupos'] as $grupo) {
            fputcsv($saida, ['Lotação: ' . $grupo['lotacao']], ';');
            fputcsv($saida, $this->cabecalho(), ';');
            foreach ($grupo['linhas'] as $linha) {
                fputcsv($saida, array_merge(
                    [$linha['nome'], str_replace("\n", ' | ', $this->textoEmpenhos($linha))],
                    array_map($formatar, $this->valores($linha)),
                    [self::SITUACAO[$linha['situacao']] ?? '']
                ), ';');
            }
            fputcsv($saida, array_merge(['TOTAL ' . $grupo['lotacao'], ''], array_map($formatar, $this->valores($grupo['total']))), ';');
            fputcsv($saida, [], ';');
        }
        fputcsv($saida, array_merge(['TOTAL GERAL', ''], array_map($formatar, $this->valores($this->resumo['total']))), ';');
        fclose($saida);
    }
}
