<?php
/**
 * TermoAdesaoService.php
 * Monta os dados do voluntário no formato esperado pelo modelo do
 * Termo de Adesão e gera os arquivos DOCX (a partir do modelo em
 * templates/anexo_v_modelo.docx) e PDF (a partir do modelo HTML em
 * templates/anexo_v_pdf.php), sem alterar o texto das cláusulas.
 */

declare(strict_types=1);

use PhpOffice\PhpWord\TemplateProcessor;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;

class TermoAdesaoService
{
    private const SEXO_LABEL = [
        'masculino' => 'masculino',
        'feminino' => 'feminino',
        'outro' => 'outro',
        'nao_informar' => 'não informado',
    ];

    private const ESTADO_CIVIL_LABEL = [
        'solteiro' => 'solteiro(a)',
        'casado' => 'casado(a)',
        'divorciado' => 'divorciado(a)',
        'viuvo' => 'viúvo(a)',
        'uniao_estavel' => 'em união estável',
    ];

    private const DIAS_SEMANA_LABEL = [
        'SEG' => 'Segunda-feira',
        'TER' => 'Terça-feira',
        'QUA' => 'Quarta-feira',
        'QUI' => 'Quinta-feira',
        'SEX' => 'Sexta-feira',
        'SAB' => 'Sábado',
        'DOM' => 'Domingo',
    ];

    private const ORDEM_DIAS = ['SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB', 'DOM'];

    private string $templateDocx;
    private string $templatePdf;
    private string $storageDir;

    public function __construct()
    {
        $this->templateDocx = __DIR__ . '/../templates/anexo_v_modelo.docx';
        $this->templatePdf = __DIR__ . '/../templates/anexo_v_pdf.php';
        $this->storageDir = __DIR__ . '/../documentos/termos';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
    }

    /**
     * Confere se todos os dados necessários para gerar o Termo existem.
     * @return string[] lista de mensagens de erro (vazia se estiver tudo ok)
     */
    public function validarParaGeracao(array $voluntario): array
    {
        return Validator::validarVoluntario($voluntario, false);
    }

    /** Gera o DOCX preenchido e devolve metadados do arquivo salvo. */
    public function gerarDocx(array $voluntario, int $versao, ?string $dataEmissao = null): array
    {
        $dados = $this->montarDadosSubstituicao($voluntario, $dataEmissao);

        $processor = new TemplateProcessor($this->templateDocx);
        foreach ($dados as $chave => $valor) {
            $processor->setValue($chave, htmlspecialchars((string) $valor, ENT_XML1, 'UTF-8'));
        }

        $pasta = $this->prepararPastaVoluntario((int) $voluntario['id']);
        $caminhoInterno = $pasta . "/{$this->slugNome($voluntario['nome'])}_{$voluntario['id']}_v{$versao}_docx.docx";
        $processor->saveAs($caminhoInterno);

        return $this->montarMetadadosArquivo($voluntario, 'docx', $versao, $caminhoInterno);
    }

    /** Gera o PDF preenchido e devolve metadados do arquivo salvo. */
    public function gerarPdf(array $voluntario, int $versao, ?string $dataEmissao = null): array
    {
        $html = $this->renderizarHtml($voluntario, $dataEmissao);

        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pasta = $this->prepararPastaVoluntario((int) $voluntario['id']);
        $caminhoInterno = $pasta . "/{$this->slugNome($voluntario['nome'])}_{$voluntario['id']}_v{$versao}_pdf.pdf";
        file_put_contents($caminhoInterno, $dompdf->output());

        return $this->montarMetadadosArquivo($voluntario, 'pdf', $versao, $caminhoInterno);
    }

    /** Renderiza o HTML do Termo (usado tanto para pré-visualização quanto para o PDF). */
    public function renderizarHtml(array $voluntario, ?string $dataEmissao = null): string
    {
        $d = array_change_key_case($this->montarDadosSubstituicao($voluntario, $dataEmissao), CASE_LOWER);
        ob_start();
        (function () use ($d) {
            include $this->templatePdf;
        })();
        return (string) ob_get_clean();
    }

    private function montarMetadadosArquivo(array $voluntario, string $formato, int $versao, string $caminhoInterno): array
    {
        $extensao = $formato === 'pdf' ? 'pdf' : 'docx';
        // Nome "bonito" sugerido ao usuário no download (especificação, seção 18).
        // O nome físico em disco (caminhoInterno) inclui ID e versão para nunca
        // colidir entre voluntários com nomes iguais nem sobrescrever versões
        // anteriores (histórico de documentos, seção 17).
        $nomeExibicao = 'Termo_Adesao_' . $this->slugNome($voluntario['nome']) . '.' . $extensao;
        $caminhoRelativo = 'documentos/termos/' . basename($caminhoInterno);

        return [
            'nomeArquivo' => $nomeExibicao,
            'caminhoArquivo' => $caminhoRelativo,
            'caminhoAbsoluto' => $caminhoInterno,
            'versao' => $versao,
            'tipoDocumento' => $formato === 'pdf' ? 'Termo de Adesão (PDF)' : 'Termo de Adesão (DOCX)',
        ];
    }

    private function prepararPastaVoluntario(int $idVoluntario): string
    {
        return $this->storageDir;
    }

    /** Monta o array (chaves em MAIÚSCULAS, iguais às do modelo) usado para preencher o Termo. */
    private function montarDadosSubstituicao(array $v, ?string $dataEmissao = null): array
    {
        [$area, $qualificacao] = $this->derivarSecretario($v['secretaria'] ?? '');

        $emissao = $dataEmissao ? new DateTime($dataEmissao) : new DateTime('today');
        $mesesPt = [
            1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
            7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
        ];

        return [
            'NUMERO_TERMO' => str_pad((string) $v['id'], 4, '0', STR_PAD_LEFT),
            'ANO_TERMO' => $emissao->format('Y'),

            'SECRETARIA' => $v['secretaria'] ?? '',
            'NOME_SECRETARIO' => $v['nomeSecretario'] ?? '',
            'QUALIFICACAO_SECRETARIO' => $qualificacao,
            'AREA_SECRETARIO' => $area,

            'NOME_VOLUNTARIO' => $v['nome'] ?? '',
            'CPF' => $v['cpf'] ?? '',
            'RG' => $v['rg'] ?? '',
            'ORGAO_EXPEDIDOR' => $v['rgOrgaoExpedidor'] ?? '',
            'DATA_EXPEDICAO' => $this->formatarDataBR($v['rgDataExpedicao'] ?? ''),
            'IDADE' => !empty($v['dataNascimento']) ? (string) Validator::calcularIdade($v['dataNascimento']) : '',
            'ESTADO_CIVIL' => self::ESTADO_CIVIL_LABEL[$v['estadoCivil'] ?? ''] ?? ($v['estadoCivil'] ?? ''),
            'SEXO' => self::SEXO_LABEL[$v['sexo'] ?? ''] ?? ($v['sexo'] ?? ''),
            'ESCOLARIDADE' => $v['escolaridade'] ?? '',
            'ENDERECO' => $this->montarEndereco($v),

            'LOCAL_PRESTACAO' => $v['localPrestacao'] ?? '',
            'DATA_INICIO' => $this->formatarDataBR($v['dataInicio'] ?? ''),
            'DATA_TERMINO' => $this->formatarDataBR($v['dataTermino'] ?? ''),
            'HORARIO_INICIO' => $this->formatarHora($v['horarioInicio'] ?? ''),
            'HORARIO_TERMINO' => $this->formatarHora($v['horarioTermino'] ?? ''),
            'DIAS_SEMANA' => $this->formatarDiasSemana($v['diasSemana'] ?? ''),

            'DIA_EMISSAO' => $emissao->format('d'),
            'MES_EMISSAO' => $mesesPt[(int) $emissao->format('n')],
            'ANO_EMISSAO' => $emissao->format('Y'),
        ];
    }

    private function derivarSecretario(string $secretaria): array
    {
        if (preg_match('/^Secretaria Municipal de (.+)$/ui', trim($secretaria), $m)) {
            $area = 'de ' . $m[1];
            $qualificacao = 'Secretário(a) Municipal de ' . $m[1];
            return [$area, $qualificacao];
        }

        $area = $secretaria !== '' ? 'responsável pela ' . $secretaria : '';
        $qualificacao = $secretaria !== '' ? 'Secretário(a) responsável pela ' . $secretaria : '';
        return [$area, $qualificacao];
    }

    private function formatarDiasSemana(string $diasSemanaCsv): string
    {
        $codigos = array_filter(array_map('trim', explode(',', $diasSemanaCsv)));
        if (empty($codigos)) {
            return '';
        }

        // Se não for um código conhecido (texto livre digitado pelo usuário), devolve como está.
        $codigosValidos = array_intersect($codigos, self::ORDEM_DIAS);
        if (count($codigosValidos) !== count($codigos)) {
            return $diasSemanaCsv;
        }

        $ordenados = array_values(array_intersect(self::ORDEM_DIAS, $codigos));

        if ($ordenados === ['SEG', 'TER', 'QUA', 'QUI', 'SEX']) {
            return 'Segunda a sexta-feira';
        }
        if ($ordenados === self::ORDEM_DIAS) {
            return 'todos os dias da semana';
        }
        if ($ordenados === ['SAB', 'DOM']) {
            return 'Sábado e Domingo';
        }

        $nomes = array_map(fn($c) => self::DIAS_SEMANA_LABEL[$c], $ordenados);
        if (count($nomes) === 1) {
            return $nomes[0];
        }
        $ultimo = array_pop($nomes);
        return implode(', ', $nomes) . ' e ' . $ultimo;
    }

    private function montarEndereco(array $v): string
    {
        $partes = [];
        $logradouroNumero = trim(($v['logradouro'] ?? '') . ($v['numero'] ? ', nº ' . $v['numero'] : ''));
        if ($logradouroNumero !== '') {
            $partes[] = $logradouroNumero;
        }
        if (!empty($v['complemento'])) {
            $partes[] = $v['complemento'];
        }
        if (!empty($v['bairro'])) {
            $partes[] = $v['bairro'];
        }

        $cidadeUf = trim(($v['cidade'] ?? '') . (!empty($v['estado']) ? ' - ' . $v['estado'] : ''));
        if ($cidadeUf !== '') {
            $partes[] = $cidadeUf;
        }

        $endereco = implode(', ', $partes);
        if (!empty($v['cep'])) {
            $endereco .= ', CEP ' . $v['cep'];
        }
        return $endereco;
    }

    private function formatarDataBR(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        $partes = explode('-', substr($iso, 0, 10));
        if (count($partes) !== 3) {
            return $iso;
        }
        [$ano, $mes, $dia] = $partes;
        return "{$dia}/{$mes}/{$ano}";
    }

    private function formatarHora(string $hora): string
    {
        return substr($hora, 0, 5);
    }

    private const MAPA_ACENTOS = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ];

    /** Remove acentuação/espacos do nome para compor o nome de arquivo (Termo_Adesao_Nome_do_Voluntario.pdf). */
    private function slugNome(string $nome): string
    {
        $semAcento = strtr($nome, self::MAPA_ACENTOS);
        $limpo = preg_replace('/[^A-Za-z0-9]+/', '_', $semAcento);
        $limpo = trim((string) $limpo, '_');
        return $limpo !== '' ? $limpo : 'Voluntario';
    }
}
