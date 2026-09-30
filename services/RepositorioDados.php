<?php
/**
 * services/RepositorioDados.php
 *
 * Contrato da camada de dados do sistema. Os Services de domínio
 * (VoluntarioService, ContratoService, DocumentoService,
 * ListaSimplesService) dependem SOMENTE desta classe — nunca de
 * GoogleSheetsService diretamente. Assim:
 *
 *     VERSÃO TEMPORÁRIA:  Services -> GoogleSheetsService (extends RepositorioDados) -> Google Sheets
 *     VERSÃO FUTURA:      Services -> MySQLService        (extends RepositorioDados) -> MySQL
 *
 * Para migrar, basta criar um MySQLService que implemente os 5 métodos
 * abstratos abaixo e trocar a instância em repositorioDados()
 * (bootstrap.php). Os métodos "nomeados" (getVoluntarios(),
 * createContrato() etc.) já vêm prontos aqui, construídos sobre os
 * genéricos.
 *
 * Convenções:
 *   - "Entidade" é a chave lógica configurada em config/google-sheets.php
 *     ('voluntarios', 'contratos', 'cargos', 'secretarias', 'locais',
 *     'secretarios', 'documentos') — numa implementação MySQL, é a tabela.
 *   - Cada registro é um array [nomeDoCampo => valor], em que os nomes dos
 *     campos são os mesmos do cabeçalho da planilha ('ID', 'Nome',
 *     'ID Voluntario'...).
 *   - Todo registro é identificado pelo campo 'ID' (inteiro).
 */

declare(strict_types=1);

abstract class RepositorioDados
{
    // ------------------------------------------------------------------
    // Operações genéricas (a implementar por cada armazenamento)
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> todos os registros da entidade. */
    abstract public function listar(string $entidade): array;

    /** @return array<string, mixed>|null */
    abstract public function buscarPorId(string $entidade, int $id): ?array;

    /**
     * Insere um registro. Se 'ID' não for informado, o armazenamento gera
     * o próximo ID disponível.
     * @return array<string, mixed> o registro gravado (com 'ID').
     */
    abstract public function inserir(string $entidade, array $dados): array;

    /** Atualiza o registro com o ID informado. Campos ausentes em $dados são mantidos. */
    abstract public function atualizar(string $entidade, int $id, array $dados): void;

    /** Exclui o registro com o ID informado (não faz nada se ele não existir). */
    abstract public function excluir(string $entidade, int $id): void;

    // ------------------------------------------------------------------
    // Utilitário genérico (implementações podem sobrescrever com uma
    // consulta mais eficiente, ex.: WHERE no MySQL)
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> registros cujo campo seja igual ao valor dado. */
    public function buscarTodosPor(string $entidade, string $campo, $valor): array
    {
        return array_values(array_filter(
            $this->listar($entidade),
            fn (array $registro) => (string) ($registro[$campo] ?? '') === (string) $valor
        ));
    }

    // ------------------------------------------------------------------
    // Métodos nomeados (especificação, seção 22)
    // ------------------------------------------------------------------

    public function getVoluntarios(): array { return $this->listar('voluntarios'); }
    public function getVoluntarioById(int $id): ?array { return $this->buscarPorId('voluntarios', $id); }
    public function createVoluntario(array $dados): array { return $this->inserir('voluntarios', $dados); }
    public function updateVoluntario(int $id, array $dados): void { $this->atualizar('voluntarios', $id, $dados); }
    public function deleteVoluntario(int $id): void { $this->excluir('voluntarios', $id); }

    /** @param int|null $idVoluntario filtra pelos contratos de um voluntário. */
    public function getContratos(?int $idVoluntario = null): array
    {
        return $idVoluntario === null
            ? $this->listar('contratos')
            : $this->buscarTodosPor('contratos', 'ID Voluntario', $idVoluntario);
    }
    public function createContrato(array $dados): array { return $this->inserir('contratos', $dados); }
    public function updateContrato(int $id, array $dados): void { $this->atualizar('contratos', $id, $dados); }
    public function deleteContrato(int $id): void { $this->excluir('contratos', $id); }

    public function getSecretarias(): array { return $this->listar('secretarias'); }
    public function getCargos(): array { return $this->listar('cargos'); }
    public function getLocais(): array { return $this->listar('locais'); }
    public function getSecretarios(): array { return $this->listar('secretarios'); }

    public function getDocumentos(?int $idVoluntario = null): array
    {
        return $idVoluntario === null
            ? $this->listar('documentos')
            : $this->buscarTodosPor('documentos', 'ID Voluntario', $idVoluntario);
    }
    public function createDocumento(array $dados): array { return $this->inserir('documentos', $dados); }
}
