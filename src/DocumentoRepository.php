<?php
/**
 * src/DocumentoRepository.php
 *
 * Camada de compatibilidade: mantém a mesma interface pública que a
 * versão anterior (baseada em MySQL) expunha para api/documentos.php,
 * agora delegando para services/DocumentoService.php (aba "Documentos"
 * do Google Sheets).
 */

declare(strict_types=1);

class DocumentoRepository
{
    private DocumentoService $servico;

    public function __construct()
    {
        $this->servico = new DocumentoService();
    }

    /** Próxima versão para um tipo de documento de um voluntário (1, 2, 3...). */
    public function proximaVersao(int $idVoluntario, string $tipoDocumento): int
    {
        return $this->servico->proximaVersao($idVoluntario, $tipoDocumento);
    }

    public function registrar(array $dados): array
    {
        return $this->servico->registrar($dados);
    }

    public function buscarPorId(int $id): ?array
    {
        return $this->servico->buscarPorId($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function listarPorVoluntario(int $idVoluntario): array
    {
        return $this->servico->listarPorVoluntario($idVoluntario);
    }
}
