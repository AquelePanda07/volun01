<?php
/**
 * DocumentoRepository.php
 * Acesso a dados da tabela `documentos_gerados` (histórico de Termos de
 * Adesão gerados em PDF/DOCX para cada voluntário).
 */

declare(strict_types=1);

class DocumentoRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::conexao();
    }

    /** Próxima versão para um tipo de documento de um voluntário (1, 2, 3...). */
    public function proximaVersao(int $idVoluntario, string $tipoDocumento): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(versao), 0) FROM documentos_gerados WHERE id_voluntario = :id AND tipo_documento = :tipo'
        );
        $stmt->execute(['id' => $idVoluntario, 'tipo' => $tipoDocumento]);
        return ((int) $stmt->fetchColumn()) + 1;
    }

    public function registrar(array $dados): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO documentos_gerados (id_voluntario, tipo_documento, nome_arquivo, caminho_arquivo, versao, usuario_responsavel)
             VALUES (:id_voluntario, :tipo_documento, :nome_arquivo, :caminho_arquivo, :versao, :usuario_responsavel)'
        );
        $stmt->execute([
            'id_voluntario' => $dados['idVoluntario'],
            'tipo_documento' => $dados['tipoDocumento'],
            'nome_arquivo' => $dados['nomeArquivo'],
            'caminho_arquivo' => $dados['caminhoArquivo'],
            'versao' => $dados['versao'],
            'usuario_responsavel' => $dados['usuarioResponsavel'] ?? 'Administrador',
        ]);

        return $this->buscarPorId((int) $this->pdo->lastInsertId());
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documentos_gerados WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();
        return $linha ? $this->paraCamelCase($linha) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listarPorVoluntario(int $idVoluntario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documentos_gerados WHERE id_voluntario = :id ORDER BY data_geracao DESC, id DESC'
        );
        $stmt->execute(['id' => $idVoluntario]);
        return array_map([$this, 'paraCamelCase'], $stmt->fetchAll());
    }

    private function paraCamelCase(array $linha): array
    {
        return [
            'id' => (int) $linha['id'],
            'idVoluntario' => (int) $linha['id_voluntario'],
            'tipoDocumento' => $linha['tipo_documento'],
            'nomeArquivo' => $linha['nome_arquivo'],
            'caminhoArquivo' => $linha['caminho_arquivo'],
            'versao' => (int) $linha['versao'],
            'usuarioResponsavel' => $linha['usuario_responsavel'],
            'dataGeracao' => $linha['data_geracao'],
        ];
    }
}
