<?php
/**
 * src/VoluntarioRepository.php
 *
 * Camada de compatibilidade: mantém exatamente a mesma interface
 * pública (listar/buscarPorId/existeCpf/criar/atualizar/excluir) que a
 * versão anterior — baseada em MySQL — expunha para api/voluntarios.php
 * e para TermoAdesaoService. Por baixo dos panos, agora delega para
 * services/VoluntarioService.php e services/ContratoService.php, que
 * falam com o Google Sheets (banco de dados temporário do sistema).
 *
 * Isso é o que permite que a API e o front-end continuem exatamente
 * iguais depois da migração. A troca futura por MySQL acontece uma
 * camada abaixo, em repositorioDados() (bootstrap.php).
 *
 * Cada voluntário retornado é um objeto "achatado": dados pessoais
 * (aba Voluntarios) + dados do contrato vigente (aba Contratos) juntos
 * no mesmo array, como o front-end sempre esperou.
 */

declare(strict_types=1);

class VoluntarioRepository
{
    private VoluntarioService $voluntarios;
    private ContratoService $contratos;

    public function __construct()
    {
        $this->voluntarios = new VoluntarioService();
        $this->contratos = new ContratoService();
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        return array_map(fn (array $v) => $this->comContrato($v), $this->voluntarios->listar());
    }

    public function buscarPorId(int $id): ?array
    {
        $voluntario = $this->voluntarios->buscarPorId($id);
        return $voluntario ? $this->comContrato($voluntario) : null;
    }

    public function existeCpf(string $cpf, ?int $idIgnorar = null): bool
    {
        return $this->voluntarios->existeCpf($cpf, $idIgnorar);
    }

    public function criar(array $dados): array
    {
        $voluntario = $this->voluntarios->criar($dados);
        $this->contratos->criar($voluntario['id'], $dados);
        return $this->buscarPorId($voluntario['id']);
    }

    public function atualizar(int $id, array $dados): array
    {
        $this->voluntarios->atualizar($id, $dados);
        $this->contratos->atualizar($id, $dados);
        return $this->buscarPorId($id);
    }

    public function excluir(int $id): void
    {
        // Remove primeiro os contratos relacionados para manter a
        // consistência entre as abas (especificação, seção 15).
        $this->contratos->excluirPorVoluntario($id);
        $this->voluntarios->excluir($id);
    }

    /** Junta o voluntário com os dados do seu contrato vigente em um único array camelCase. */
    private function comContrato(array $voluntario): array
    {
        $contrato = $this->contratos->buscarAtualPorVoluntario($voluntario['id']);
        if ($contrato !== null) {
            unset($contrato['id'], $contrato['idVoluntario'], $contrato['dataCadastro']);
        }
        return array_merge($voluntario, $contrato ?? []);
    }
}
