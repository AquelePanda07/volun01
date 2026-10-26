<?php
/**
 * services/AnexoService.php
 * Arquivo único com os documentos pessoais de cada voluntário (aba "Anexos"):
 * normalmente um PDF com RG, CPF, comprovante de luz, histórico escolar e
 * certidão negativa juntos. Há no máximo um arquivo por voluntário —
 * anexar de novo substitui o anterior — e uma lista (coluna "Documentos")
 * de quais documentos estão dentro dele.
 *
 * O arquivo fica em documentos/pessoais/<id do voluntário>/, fora do que o
 * router.php serve; o download passa sempre por api/anexos.php.
 */

declare(strict_types=1);

class AnexoService
{
    /** Documentos que podem ser marcados como presentes no arquivo (chave => rótulo). */
    public const DOCUMENTOS = [
        'rg' => 'RG',
        'cpf' => 'CPF',
        'comprovante_luz' => 'Comprovante de Luz',
        'historico_escolar' => 'Histórico Escolar',
        'certidao_negativa' => 'Certidão Negativa',
    ];

    private const TAMANHO_MAXIMO = 20 * 1024 * 1024; // igual ao limite do front-end (app.js)

    private const DIR_ANEXOS = __DIR__ . '/../documentos/pessoais';

    private RepositorioDados $dados;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
    }

    public function buscarPorVoluntario(int $idVoluntario): ?array
    {
        $linha = $this->linhaDoVoluntario($idVoluntario);
        return $linha ? $this->paraCamelCase($linha) : null;
    }

    /** @return array<int, array<string, mixed>> o arquivo de cada voluntário que tem um (página "Documentos"). */
    public function listarTodos(): array
    {
        return array_map([$this, 'paraCamelCase'], $this->dados->listar('anexos'));
    }

    /**
     * Anexa (ou substitui) o arquivo do voluntário. $arquivo é um data URL
     * ("data:application/pdf;base64,..."). Substituir mantém a lista de documentos marcados.
     * @return string[] erros (vazio = anexado).
     */
    public function anexar(int $idVoluntario, string $nomeOriginal, string $arquivo): array
    {
        if ($this->dados->getVoluntarioById($idVoluntario) === null) {
            return ['Voluntário não encontrado.'];
        }
        if (!preg_match('/^data:([\w.+\/-]+);base64,(.*)$/s', $arquivo, $m)) {
            return ['Selecione o arquivo com os documentos.'];
        }
        $binario = base64_decode($m[2], true);
        if ($binario === false || $binario === '') {
            return ['Não foi possível ler o arquivo enviado.'];
        }
        if (strlen($binario) > self::TAMANHO_MAXIMO) {
            return ['O arquivo não pode passar de ' . (self::TAMANHO_MAXIMO / 1024 / 1024) . ' MB.'];
        }
        // O tipo vem do conteúdo real do arquivo, não do que o navegador declarou.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($binario);
        $extensao = ContratoService::EXTENSOES_ANEXO[$mime] ?? null;
        if ($extensao === null) {
            return ['Formato não aceito. Envie os documentos em PDF ou como foto (JPG, PNG ou WEBP).'];
        }

        $pasta = self::DIR_ANEXOS . '/' . $idVoluntario;
        if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new RuntimeException('Não foi possível criar a pasta dos documentos.');
        }
        $nomeArquivo = sprintf('documentos_%s.%s', date('Ymd_His'), $extensao);
        if (file_put_contents("{$pasta}/{$nomeArquivo}", $binario) === false) {
            throw new RuntimeException('Não foi possível salvar o arquivo.');
        }

        $campos = [
            'Nome Original' => mb_substr(basename(trim($nomeOriginal)) ?: $nomeArquivo, 0, 180),
            'Caminho Arquivo' => "{$idVoluntario}/{$nomeArquivo}",
            'Tamanho' => strlen($binario),
            'Data Envio' => date('Y-m-d H:i:s'),
        ];
        $atual = $this->linhaDoVoluntario($idVoluntario);
        if ($atual === null) {
            $this->dados->inserir('anexos', ['ID Voluntario' => $idVoluntario, 'Documentos' => ''] + $campos);
        } else {
            $this->dados->atualizar('anexos', (int) $atual['ID'], $campos);
            $this->apagarArquivo((string) ($atual['Caminho Arquivo'] ?? ''));
        }
        return [];
    }

    /**
     * Define quais documentos estão dentro do arquivo. Só é possível depois de anexá-lo.
     * @param string[] $documentos chaves de DOCUMENTOS
     * @return string[] erros
     */
    public function marcarDocumentos(int $idVoluntario, array $documentos): array
    {
        $atual = $this->linhaDoVoluntario($idVoluntario);
        if ($atual === null) {
            return ['Anexe o arquivo com os documentos antes de marcar o que ele contém.'];
        }
        // Mantém a ordem de DOCUMENTOS e ignora chaves desconhecidas.
        $validos = array_values(array_intersect(array_keys(self::DOCUMENTOS), array_map('strval', $documentos)));
        $this->dados->atualizar('anexos', (int) $atual['ID'], ['Documentos' => implode(',', $validos)]);
        return [];
    }

    /** Apaga o arquivo do voluntário e a lista de documentos marcados. @return string[] erros */
    public function remover(int $idVoluntario): array
    {
        $atual = $this->linhaDoVoluntario($idVoluntario);
        if ($atual === null) {
            return ['Não há arquivo anexado para remover.'];
        }
        $this->dados->excluir('anexos', (int) $atual['ID']);
        $this->apagarArquivo((string) ($atual['Caminho Arquivo'] ?? ''));
        return [];
    }

    /**
     * Dados para servir o arquivo do voluntário, ou null se não houver.
     * @return array{caminho: string, nome: string}|null
     */
    public function arquivo(int $idVoluntario): ?array
    {
        $atual = $idVoluntario > 0 ? $this->linhaDoVoluntario($idVoluntario) : null;
        $caminho = $atual ? $this->caminhoSeguro((string) ($atual['Caminho Arquivo'] ?? '')) : null;
        if ($caminho === null) {
            return null;
        }
        // Nome do download: o original, mas com a extensão real do arquivo salvo.
        $base = pathinfo((string) ($atual['Nome Original'] ?? ''), PATHINFO_FILENAME) ?: 'documentos';
        return ['caminho' => $caminho, 'nome' => $base . '.' . pathinfo($caminho, PATHINFO_EXTENSION)];
    }

    /** Remove o arquivo de um voluntário (usado ao excluir o voluntário). */
    public function excluirPorVoluntario(int $idVoluntario): void
    {
        foreach ($this->dados->buscarTodosPor('anexos', 'ID Voluntario', $idVoluntario) as $linha) {
            $this->apagarArquivo((string) ($linha['Caminho Arquivo'] ?? ''));
            $this->dados->excluir('anexos', (int) $linha['ID']);
        }
        @rmdir(self::DIR_ANEXOS . '/' . $idVoluntario); // só some se ficou vazia
    }

    // ------------------------------------------------------------------

    private function linhaDoVoluntario(int $idVoluntario): ?array
    {
        $linhas = $this->dados->buscarTodosPor('anexos', 'ID Voluntario', $idVoluntario);
        return $linhas ? end($linhas) : null; // há só uma; se houver mais, vale a mais recente
    }

    /** Resolve "5/documentos_....pdf" dentro de DIR_ANEXOS, recusando qualquer caminho que saia da pasta. */
    private function caminhoSeguro(string $relativo): ?string
    {
        if (!preg_match('#^\d+/[\w.-]+$#', $relativo) || str_contains($relativo, '..')) {
            return null;
        }
        $caminho = self::DIR_ANEXOS . '/' . $relativo;
        return is_file($caminho) ? $caminho : null;
    }

    private function apagarArquivo(string $relativo): void
    {
        $caminho = $relativo !== '' ? $this->caminhoSeguro($relativo) : null;
        if ($caminho !== null) {
            @unlink($caminho);
        }
    }

    /** O caminho no disco não é exposto: o front-end baixa pelo ID do voluntário. */
    private function paraCamelCase(array $linha): array
    {
        $documentos = trim((string) ($linha['Documentos'] ?? ''));
        return [
            'idVoluntario' => (int) ($linha['ID Voluntario'] ?? 0),
            'documentos' => $documentos === '' ? [] : explode(',', $documentos),
            'nomeOriginal' => (string) ($linha['Nome Original'] ?? ''),
            'tamanho' => (int) ($linha['Tamanho'] ?? 0),
            'dataEnvio' => (string) ($linha['Data Envio'] ?? ''),
        ];
    }
}
