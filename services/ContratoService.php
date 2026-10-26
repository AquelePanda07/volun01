<?php
/**
 * services/ContratoService.php
 * Regras de negócio para a aba "Contratos" do Google Sheets.
 *
 * Cada linha representa um contrato de prestação de serviço voluntário
 * vinculado a um voluntário (coluna "ID Voluntario"). Hoje a interface
 * trabalha com um único contrato "vigente" por voluntário (o mais
 * recente), mas o modelo já suporta histórico de múltiplos contratos
 * por voluntário, caso o sistema evolua nesse sentido no futuro.
 *
 * O "Status" é sempre recalculado a partir das datas (nunca é lido como
 * verdade absoluta) — a coluna na planilha existe apenas para que quem
 * abrir a planilha diretamente consiga ler o status sem precisar do
 * sistema (ver especificação, seção 12).
 */

declare(strict_types=1);

class ContratoService
{
    /** camelCase (front-end) => cabeçalho da coluna na planilha "Contratos". */
    private const MAPA_COLUNAS = [
        'cargaHoraria' => 'Carga Horaria',
        'cargo' => 'Cargo',
        'localPrestacao' => 'Local Prestacao',
        'dataInicio' => 'Data Inicio',
        'dataTermino' => 'Data Termino',
        'horarioInicio' => 'Horario Inicio',
        'horarioTermino' => 'Horario Termino',
        'diasSemana' => 'Dias Semana',
        'secretaria' => 'Secretaria',
        'nomeSecretario' => 'Nome Secretario',
    ];

    /** A partir de quantos dias antes do término a renovação fica disponível. */
    public const JANELA_RENOVACAO_DIAS = 60;

    /** Tipos aceitos para o Termo de Adesão assinado: MIME => extensão do arquivo salvo. */
    public const EXTENSOES_ANEXO = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const TAMANHO_MAXIMO_ANEXO = 10 * 1024 * 1024; // igual ao limite do front-end (app.js)

    /** Colunas de cada termo assinado: o do contrato e o da renovação. */
    private const COLUNAS_TERMO = [
        'termo' => ['anexo' => 'Anexo Termo', 'assinado' => 'Termo Assinado', 'data' => 'Data Assinatura Termo'],
        'renovacao' => ['anexo' => 'Anexo Renovacao', 'assinado' => 'Renovacao Assinada', 'data' => 'Data Assinatura Renovacao'],
    ];

    /** Pasta dos termos assinados (fora do que o router.php serve; o download passa por api/contratos.php). */
    private const DIR_ANEXOS = __DIR__ . '/../documentos/assinados';

    private RepositorioDados $dados;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
    }

    /** @return array<int, array<string, mixed>> todos os contratos, mais recente primeiro. */
    public function listarTodos(): array
    {
        $linhas = array_map([$this, 'paraCamelCase'], $this->dados->getContratos());
        usort($linhas, fn (array $a, array $b) => $b['id'] <=> $a['id']);
        return $linhas;
    }

    /** @return array<int, array<string, mixed>> histórico de contratos do voluntário, mais recente primeiro. */
    public function listarPorVoluntario(int $idVoluntario): array
    {
        $linhas = array_map([$this, 'paraCamelCase'], $this->dados->getContratos($idVoluntario));
        usort($linhas, fn (array $a, array $b) => $b['id'] <=> $a['id']);
        return $linhas;
    }

    /** Contrato vigente (o mais recente) usado para compor a visão consolidada do voluntário. */
    public function buscarAtualPorVoluntario(int $idVoluntario): ?array
    {
        return $this->listarPorVoluntario($idVoluntario)[0] ?? null;
    }

    public function criar(int $idVoluntario, array $dados): array
    {
        $linha = $this->paraColunas($dados);
        $linha['ID Voluntario'] = (string) $idVoluntario;
        $linha['Status'] = self::calcularStatus((string) ($dados['dataInicio'] ?? ''), (string) ($dados['dataTermino'] ?? ''));
        $linha['Data Cadastro'] = date('Y-m-d H:i:s');

        return $this->paraCamelCase($this->dados->createContrato($linha));
    }

    /** Atualiza o contrato vigente do voluntário. Nunca cria um novo registro numa edição. */
    public function atualizar(int $idVoluntario, array $dados): array
    {
        $atual = $this->buscarLinhaAtual($idVoluntario);
        if ($atual === null) {
            return $this->criar($idVoluntario, $dados);
        }

        $linha = $this->paraColunas($dados);
        $linha['ID'] = (string) $atual['ID'];
        $linha['ID Voluntario'] = (string) $idVoluntario;
        $linha['Status'] = self::calcularStatus(
            (string) ($dados['dataInicio'] ?? ($atual['Data Inicio'] ?? '')),
            (string) ($dados['dataTermino'] ?? ($atual['Data Termino'] ?? ''))
        );
        $linha['Data Cadastro'] = $atual['Data Cadastro'] ?? date('Y-m-d H:i:s');

        $this->dados->updateContrato((int) $atual['ID'], $linha);
        return $this->paraCamelCase($linha);
    }

    /**
     * Marca o contrato vigente como renovado, prorrogando a data de término.
     * O término original fica guardado em "Termino Anterior". Só é permitido
     * uma vez por contrato, a partir de JANELA_RENOVACAO_DIAS dias antes do fim
     * (ou depois de encerrado), e a prorrogação vai até 6 meses além do término
     * ou até 6 meses além de hoje.
     *
     * @return string[] erros de validação (vazio = renovado com sucesso).
     */
    public function renovar(int $idVoluntario, string $novaDataTermino): array
    {
        $atual = $this->buscarLinhaAtual($idVoluntario);
        $erros = $this->validarRenovacao($atual, $novaDataTermino);
        if (!empty($erros)) {
            return $erros;
        }

        $this->dados->updateContrato((int) $atual['ID'], [
            'Data Termino' => $novaDataTermino,
            'Renovado' => 'TRUE',
            'Data Renovacao' => date('Y-m-d'),
            'Termino Anterior' => (string) $atual['Data Termino'],
            'Status' => self::calcularStatus((string) ($atual['Data Inicio'] ?? ''), $novaDataTermino),
        ]);
        return [];
    }

    /** @return string[] */
    private function validarRenovacao(?array $atual, string $novaDataTermino): array
    {
        if ($atual === null) {
            return ['Este voluntário não possui contrato para renovar.'];
        }
        if (self::estaRenovado($atual)) {
            return ['Este contrato já foi renovado.'];
        }

        $terminoAtual = (string) ($atual['Data Termino'] ?? '');
        $status = self::calcularStatus((string) ($atual['Data Inicio'] ?? ''), $terminoAtual);
        $diasRestantes = $terminoAtual !== ''
            ? (int) (new DateTime('today'))->diff(new DateTime($terminoAtual))->format('%r%a')
            : null;
        if ($status === 'A_INICIAR' || $diasRestantes === null || $diasRestantes > self::JANELA_RENOVACAO_DIAS) {
            return ['A renovação só fica disponível nos últimos ' . self::JANELA_RENOVACAO_DIAS . ' dias do contrato.'];
        }

        $data = DateTime::createFromFormat('!Y-m-d', $novaDataTermino);
        if ($data === false || $data->format('Y-m-d') !== $novaDataTermino) {
            return ['Informe uma nova data de término válida.'];
        }
        if ($novaDataTermino <= $terminoAtual) {
            return ['A nova data de término deve ser posterior ao término atual.'];
        }
        // Os 6 meses contam a partir do término atual ou de hoje (à escolha
        // no modal), então o limite é 6 meses após a mais recente das duas.
        $base = max($terminoAtual, date('Y-m-d'));
        if (Validator::diferencaEmMeses($base, $novaDataTermino) > 6) {
            return ['A renovação não pode prorrogar o contrato por mais de 6 meses.'];
        }
        return [];
    }

    private static function estaRenovado(array $linha): bool
    {
        return strtoupper(trim((string) ($linha['Renovado'] ?? ''))) === 'TRUE';
    }

    // ------------------------------------------------------------------
    // Termo de Adesão assinado (anexo + marcação de assinado)
    // ------------------------------------------------------------------

    /**
     * Anexa (ou substitui) o termo assinado do contrato vigente. $arquivo é
     * um data URL ("data:application/pdf;base64,..."). Substituir o anexo
     * mantém a marcação de assinado.
     *
     * @return string[] erros (vazio = anexado).
     */
    public function anexarTermo(int $idVoluntario, string $tipo, string $arquivo): array
    {
        [$atual, $erro] = $this->contratoParaTermo($idVoluntario, $tipo);
        if ($erro !== null) {
            return [$erro];
        }

        if (!preg_match('/^data:([\w.+\/-]+);base64,(.*)$/s', $arquivo, $m)) {
            return ['Selecione o arquivo do termo assinado.'];
        }
        $binario = base64_decode($m[2], true);
        if ($binario === false || $binario === '') {
            return ['Não foi possível ler o arquivo enviado.'];
        }
        if (strlen($binario) > self::TAMANHO_MAXIMO_ANEXO) {
            return ['O arquivo não pode passar de ' . (self::TAMANHO_MAXIMO_ANEXO / 1024 / 1024) . ' MB.'];
        }
        // O tipo vem do conteúdo real do arquivo, não do que o navegador declarou.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($binario);
        $extensao = self::EXTENSOES_ANEXO[$mime] ?? null;
        if ($extensao === null) {
            return ['Formato não aceito. Envie o termo em PDF ou como foto (JPG, PNG ou WEBP).'];
        }

        $pasta = self::DIR_ANEXOS . '/' . $idVoluntario;
        if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new RuntimeException('Não foi possível criar a pasta dos termos assinados.');
        }
        $nomeArquivo = sprintf('%s_contrato%d_%s.%s', $tipo, (int) $atual['ID'], date('Ymd_His'), $extensao);
        if (file_put_contents("{$pasta}/{$nomeArquivo}", $binario) === false) {
            throw new RuntimeException('Não foi possível salvar o termo assinado.');
        }

        $colunas = self::COLUNAS_TERMO[$tipo];
        $anterior = (string) ($atual[$colunas['anexo']] ?? '');
        $this->dados->updateContrato((int) $atual['ID'], [$colunas['anexo'] => "{$idVoluntario}/{$nomeArquivo}"]);
        $this->apagarArquivoAnexo($anterior);
        return [];
    }

    /** Apaga o anexo e desfaz a marcação de assinado (sem anexo não há assinatura). */
    public function removerAnexoTermo(int $idVoluntario, string $tipo): array
    {
        [$atual, $erro] = $this->contratoParaTermo($idVoluntario, $tipo);
        if ($erro !== null) {
            return [$erro];
        }
        $colunas = self::COLUNAS_TERMO[$tipo];
        $anterior = (string) ($atual[$colunas['anexo']] ?? '');
        if ($anterior === '') {
            return ['Não há termo anexado para remover.'];
        }

        $this->dados->updateContrato((int) $atual['ID'], [
            $colunas['anexo'] => '',
            $colunas['assinado'] => '',
            $colunas['data'] => '',
        ]);
        $this->apagarArquivoAnexo($anterior);
        return [];
    }

    /** Marca/desmarca o termo como assinado. Só é possível marcar depois de anexar o arquivo. */
    public function marcarTermoAssinado(int $idVoluntario, string $tipo, bool $assinado): array
    {
        [$atual, $erro] = $this->contratoParaTermo($idVoluntario, $tipo);
        if ($erro !== null) {
            return [$erro];
        }
        $colunas = self::COLUNAS_TERMO[$tipo];
        if ($assinado && trim((string) ($atual[$colunas['anexo']] ?? '')) === '') {
            return ['Anexe o termo assinado antes de marcá-lo como assinado.'];
        }

        $this->dados->updateContrato((int) $atual['ID'], [
            $colunas['assinado'] => $assinado ? 'TRUE' : '',
            $colunas['data'] => $assinado ? date('Y-m-d') : '',
        ]);
        return [];
    }

    /** Caminho absoluto do anexo do contrato vigente, ou null se não houver (ou o arquivo sumiu). */
    public function caminhoAnexoAtual(int $idVoluntario, string $tipo): ?string
    {
        if ($idVoluntario <= 0 || !isset(self::COLUNAS_TERMO[$tipo])) {
            return null;
        }
        $atual = $this->buscarLinhaAtual($idVoluntario);
        $relativo = (string) ($atual[self::COLUNAS_TERMO[$tipo]['anexo']] ?? '');
        return $relativo !== '' ? $this->caminhoSeguroAnexo($relativo) : null;
    }

    /**
     * Contrato vigente apto a receber o termo do tipo pedido.
     * @return array{0: ?array, 1: ?string} [linha do contrato, mensagem de erro]
     */
    private function contratoParaTermo(int $idVoluntario, string $tipo): array
    {
        if (!isset(self::COLUNAS_TERMO[$tipo])) {
            return [null, 'Tipo de termo inválido.'];
        }
        $atual = $this->buscarLinhaAtual($idVoluntario);
        if ($atual === null) {
            return [null, 'Este voluntário não possui contrato.'];
        }
        if ($tipo === 'renovacao' && !self::estaRenovado($atual)) {
            return [null, 'O contrato ainda não foi renovado.'];
        }
        return [$atual, null];
    }

    /** Resolve "5/termo_....pdf" dentro de DIR_ANEXOS, recusando qualquer caminho que saia da pasta. */
    private function caminhoSeguroAnexo(string $relativo): ?string
    {
        if (!preg_match('#^\d+/[\w.-]+$#', $relativo) || str_contains($relativo, '..')) {
            return null;
        }
        $caminho = self::DIR_ANEXOS . '/' . $relativo;
        return is_file($caminho) ? $caminho : null;
    }

    private function apagarArquivoAnexo(string $relativo): void
    {
        $caminho = $relativo !== '' ? $this->caminhoSeguroAnexo($relativo) : null;
        if ($caminho !== null) {
            @unlink($caminho);
        }
    }

    /** Remove todos os contratos vinculados a um voluntário (usado ao excluir o voluntário). */
    public function excluirPorVoluntario(int $idVoluntario): void
    {
        foreach ($this->dados->getContratos($idVoluntario) as $linha) {
            foreach (self::COLUNAS_TERMO as $colunas) {
                $this->apagarArquivoAnexo((string) ($linha[$colunas['anexo']] ?? ''));
            }
            $this->dados->deleteContrato((int) $linha['ID']);
        }
        @rmdir(self::DIR_ANEXOS . '/' . $idVoluntario); // só some se ficou vazia
    }

    /** Status automático conforme a especificação (seção 12): A_INICIAR, ATIVO ou ENCERRADO. */
    public static function calcularStatus(string $dataInicio, string $dataTermino): string
    {
        $hoje = date('Y-m-d');
        if ($dataInicio !== '' && $hoje < $dataInicio) {
            return 'A_INICIAR';
        }
        if ($dataTermino !== '' && $hoje > $dataTermino) {
            return 'ENCERRADO';
        }
        return 'ATIVO';
    }

    // ------------------------------------------------------------------

    private function buscarLinhaAtual(int $idVoluntario): ?array
    {
        $linhas = $this->dados->getContratos($idVoluntario);
        if (empty($linhas)) {
            return null;
        }
        usort($linhas, fn (array $a, array $b) => ((int) $b['ID']) <=> ((int) $a['ID']));
        return $linhas[0];
    }

    private function paraColunas(array $dados): array
    {
        $linha = [];
        foreach (self::MAPA_COLUNAS as $camel => $coluna) {
            if (!array_key_exists($camel, $dados)) {
                continue;
            }
            $valor = $dados[$camel];
            if ($camel === 'diasSemana' && is_array($valor)) {
                $valor = implode(',', $valor);
            }
            $linha[$coluna] = $valor;
        }
        return $linha;
    }

    private function paraCamelCase(array $linha): array
    {
        $resultado = [
            'id' => (int) ($linha['ID'] ?? 0),
            'idVoluntario' => (int) ($linha['ID Voluntario'] ?? 0),
        ];
        foreach (self::MAPA_COLUNAS as $camel => $coluna) {
            $resultado[$camel] = $linha[$coluna] ?? '';
        }
        // Sempre recalculado: a coluna "Status" da planilha fica desatualizada com o passar dos dias.
        $resultado['status'] = self::calcularStatus((string) $resultado['dataInicio'], (string) $resultado['dataTermino']);
        $resultado['dataCadastro'] = $linha['Data Cadastro'] ?? '';
        $resultado['renovado'] = self::estaRenovado($linha);
        $resultado['dataRenovacao'] = $linha['Data Renovacao'] ?? '';
        $resultado['terminoAnterior'] = $linha['Termino Anterior'] ?? '';
        // Termos assinados: o front-end só precisa saber se há anexo (o arquivo é servido por api/contratos.php).
        foreach (self::COLUNAS_TERMO as $tipo => $colunas) {
            $prefixo = $tipo === 'termo' ? 'Termo' : 'Renovacao';
            $resultado['anexo' . $prefixo] = basename((string) ($linha[$colunas['anexo']] ?? ''));
            $resultado['dataAssinatura' . $prefixo] = $linha[$colunas['data']] ?? '';
        }
        $resultado['termoAssinado'] = strtoupper(trim((string) ($linha['Termo Assinado'] ?? ''))) === 'TRUE';
        $resultado['renovacaoAssinada'] = strtoupper(trim((string) ($linha['Renovacao Assinada'] ?? ''))) === 'TRUE';
        return $resultado;
    }
}
