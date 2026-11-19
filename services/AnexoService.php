<?php
/**
 * services/AnexoService.php
 * Arquivos com os documentos pessoais de cada voluntário (aba "Anexos"):
 * pode ser um PDF com RG, CPF, comprovante de luz, histórico escolar e
 * certidão negativa juntos, ou vários arquivos separados. Cada linha da aba é
 * um arquivo; a lista (coluna "Documentos") de quais documentos foram
 * entregues é do voluntário, e fica gravada igual em todas as linhas dele.
 *
 * Os arquivos ficam em documentos/pessoais/<id do voluntário>/, fora do que o
 * router.php serve; o download passa sempre por api/anexos.php.
 */

declare(strict_types=1);

class AnexoService
{
    /** Documentos que podem ser marcados como entregues (chave => rótulo). */
    public const DOCUMENTOS = [
        'rg' => 'RG',
        'cpf' => 'CPF',
        'comprovante_luz' => 'Comprovante de Luz',
        'historico_escolar' => 'Histórico Escolar',
        'certidao_negativa' => 'Certidão Negativa',
    ];

    // Por arquivo, igual ao limite do front-end (app.js). Em base64 o envio fica ~33% maior, por
    // isso o post_max_size do PHP precisa ser de pelo menos ~70M (ver start-server.bat).
    private const TAMANHO_MAXIMO = 50 * 1024 * 1024;

    private const DIR_ANEXOS = __DIR__ . '/../documentos/pessoais';

    private RepositorioDados $dados;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
    }

    /** Os arquivos do voluntário e a lista de documentos entregues, ou null se não houver arquivo. */
    public function buscarPorVoluntario(int $idVoluntario): ?array
    {
        return $this->agrupar($this->linhasDoVoluntario($idVoluntario))[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> um item por voluntário que tem arquivo (página "Documentos"). */
    public function listarTodos(): array
    {
        return $this->agrupar($this->dados->listar('anexos'));
    }

    /**
     * Anexa mais um arquivo ao voluntário. $arquivo é um data URL
     * ("data:application/pdf;base64,..."). O novo arquivo herda a lista de documentos marcados.
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
        // O sufixo aleatório evita colisão quando vários arquivos chegam no mesmo segundo.
        $nomeArquivo = sprintf('documentos_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(3)), $extensao);
        if (file_put_contents("{$pasta}/{$nomeArquivo}", $binario) === false) {
            throw new RuntimeException('Não foi possível salvar o arquivo.');
        }

        $existentes = $this->linhasDoVoluntario($idVoluntario);
        $this->dados->inserir('anexos', [
            'ID Voluntario' => $idVoluntario,
            'Documentos' => $existentes ? (string) (end($existentes)['Documentos'] ?? '') : '',
            'Nome Original' => mb_substr(basename(trim($nomeOriginal)) ?: $nomeArquivo, 0, 180),
            'Caminho Arquivo' => "{$idVoluntario}/{$nomeArquivo}",
            'Tamanho' => strlen($binario),
            'Data Envio' => date('Y-m-d H:i:s'),
        ]);
        return [];
    }

    /**
     * Define quais documentos foram entregues. Só é possível depois de anexar algum arquivo.
     * @param string[] $documentos chaves de DOCUMENTOS
     * @return string[] erros
     */
    public function marcarDocumentos(int $idVoluntario, array $documentos): array
    {
        $linhas = $this->linhasDoVoluntario($idVoluntario);
        if (!$linhas) {
            return ['Anexe o arquivo com os documentos antes de marcar o que foi entregue.'];
        }
        // Mantém a ordem de DOCUMENTOS e ignora chaves desconhecidas.
        $validos = implode(',', array_values(array_intersect(array_keys(self::DOCUMENTOS), array_map('strval', $documentos))));
        foreach ($linhas as $linha) {
            if ((string) ($linha['Documentos'] ?? '') !== $validos) {
                $this->dados->atualizar('anexos', (int) $linha['ID'], ['Documentos' => $validos]);
            }
        }
        return [];
    }

    /**
     * Apaga um arquivo do voluntário. Ao apagar o último, a lista de documentos marcados some junto.
     * @return string[] erros
     */
    public function remover(int $idVoluntario, int $idAnexo): array
    {
        $linha = $this->linhaDoAnexo($idVoluntario, $idAnexo);
        if ($linha === null) {
            return ['Arquivo não encontrado.'];
        }
        $this->dados->excluir('anexos', (int) $linha['ID']);
        $this->apagarArquivo((string) ($linha['Caminho Arquivo'] ?? ''));
        return [];
    }

    /**
     * Dados para servir um arquivo do voluntário, ou null se não houver.
     * @return array{caminho: string, nome: string}|null
     */
    public function arquivo(int $idVoluntario, int $idAnexo): ?array
    {
        $linha = $idVoluntario > 0 && $idAnexo > 0 ? $this->linhaDoAnexo($idVoluntario, $idAnexo) : null;
        $caminho = $linha ? $this->caminhoSeguro((string) ($linha['Caminho Arquivo'] ?? '')) : null;
        if ($caminho === null) {
            return null;
        }
        // Nome do download: o original, mas com a extensão real do arquivo salvo.
        $base = pathinfo((string) ($linha['Nome Original'] ?? ''), PATHINFO_FILENAME) ?: 'documentos';
        return ['caminho' => $caminho, 'nome' => $base . '.' . pathinfo($caminho, PATHINFO_EXTENSION)];
    }

    /** Remove os arquivos de um voluntário (usado ao excluir o voluntário). */
    public function excluirPorVoluntario(int $idVoluntario): void
    {
        foreach ($this->linhasDoVoluntario($idVoluntario) as $linha) {
            $this->apagarArquivo((string) ($linha['Caminho Arquivo'] ?? ''));
            $this->dados->excluir('anexos', (int) $linha['ID']);
        }
        @rmdir(self::DIR_ANEXOS . '/' . $idVoluntario); // só some se ficou vazia
    }

    // ------------------------------------------------------------------

    private function linhasDoVoluntario(int $idVoluntario): array
    {
        return $this->dados->buscarTodosPor('anexos', 'ID Voluntario', $idVoluntario);
    }

    /** A linha do arquivo, só se ele for mesmo deste voluntário. */
    private function linhaDoAnexo(int $idVoluntario, int $idAnexo): ?array
    {
        foreach ($this->linhasDoVoluntario($idVoluntario) as $linha) {
            if ((int) $linha['ID'] === $idAnexo) {
                return $linha;
            }
        }
        return null;
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

    /**
     * Junta as linhas por voluntário. A lista de documentos vale a da linha mais
     * recente (são todas iguais). O caminho no disco não é exposto: o
     * front-end baixa pelo ID do arquivo.
     * @return array<int, array<string, mixed>>
     */
    private function agrupar(array $linhas): array
    {
        $porVoluntario = [];
        foreach ($linhas as $linha) {
            $id = (int) ($linha['ID Voluntario'] ?? 0);
            $documentos = trim((string) ($linha['Documentos'] ?? ''));
            $porVoluntario[$id] ??= ['idVoluntario' => $id, 'documentos' => [], 'arquivos' => []];
            $porVoluntario[$id]['documentos'] = $documentos === '' ? [] : explode(',', $documentos);
            $porVoluntario[$id]['arquivos'][] = [
                'id' => (int) ($linha['ID'] ?? 0),
                'nomeOriginal' => (string) ($linha['Nome Original'] ?? ''),
                'tamanho' => (int) ($linha['Tamanho'] ?? 0),
                'dataEnvio' => (string) ($linha['Data Envio'] ?? ''),
            ];
        }
        return array_values($porVoluntario);
    }
}
