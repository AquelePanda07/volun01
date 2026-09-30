<?php
/**
 * services/DocumentoService.php
 * Regras de negócio para a aba "Documentos" do Google Sheets: histórico
 * de Termos de Adesão gerados (PDF/DOCX) para cada voluntário.
 */

declare(strict_types=1);

class DocumentoService
{
    private RepositorioDados $dados;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
    }

    /** Próxima versão para um tipo de documento de um voluntário (1, 2, 3...). */
    public function proximaVersao(int $idVoluntario, string $tipoDocumento): int
    {
        $maior = 0;
        foreach ($this->dados->getDocumentos($idVoluntario) as $linha) {
            if ((string) ($linha['Tipo Documento'] ?? '') === $tipoDocumento) {
                $maior = max($maior, (int) ($linha['Versao'] ?? 0));
            }
        }
        return $maior + 1;
    }

    public function registrar(array $dados): array
    {
        $linha = [
            'ID Voluntario' => (string) $dados['idVoluntario'],
            'Tipo Documento' => (string) $dados['tipoDocumento'],
            'Nome Arquivo' => (string) $dados['nomeArquivo'],
            'Caminho Arquivo' => (string) $dados['caminhoArquivo'],
            'Versao' => (string) $dados['versao'],
            'Usuario Responsavel' => (string) ($dados['usuarioResponsavel'] ?? 'Administrador'),
            'Data Geracao' => date('Y-m-d H:i:s'),
        ];

        return $this->paraCamelCase($this->dados->createDocumento($linha));
    }

    public function buscarPorId(int $id): ?array
    {
        $linha = $this->dados->buscarPorId('documentos', $id);
        return $linha ? $this->paraCamelCase($linha) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listarPorVoluntario(int $idVoluntario): array
    {
        $linhas = array_map([$this, 'paraCamelCase'], $this->dados->getDocumentos($idVoluntario));
        usort($linhas, function (array $a, array $b) {
            return strcmp((string) $b['dataGeracao'], (string) $a['dataGeracao']) ?: ($b['id'] <=> $a['id']);
        });
        return $linhas;
    }

    private function paraCamelCase(array $linha): array
    {
        return [
            'id' => (int) ($linha['ID'] ?? 0),
            'idVoluntario' => (int) ($linha['ID Voluntario'] ?? 0),
            'tipoDocumento' => $linha['Tipo Documento'] ?? '',
            'nomeArquivo' => $linha['Nome Arquivo'] ?? '',
            'caminhoArquivo' => $linha['Caminho Arquivo'] ?? '',
            'versao' => (int) ($linha['Versao'] ?? 0),
            'usuarioResponsavel' => $linha['Usuario Responsavel'] ?? '',
            'dataGeracao' => $linha['Data Geracao'] ?? '',
        ];
    }
}
