<?php
/**
 * services/ListaSimplesService.php
 * CRUD genérico para as abas de apoio/lookup (Cargos, Secretarias,
 * Locais, Secretarios), todas seguindo o formato "ID, ..., Ativo".
 * Evita repetir a mesma lógica em quatro classes quase idênticas.
 *
 * Também é responsável por "semear" cada aba com valores padrão na
 * primeira vez que ela é usada e ainda está vazia, para que os combos
 * do formulário de cadastro não fiquem sem opções logo de início.
 */

declare(strict_types=1);

class ListaSimplesService
{
    private GoogleSheetsService $sheets;
    private string $aba;

    /** @var string[] colunas de dados (sem "ID" nem "Ativo"), ex.: ['Nome'] ou ['Nome','Sigla']. */
    private array $colunasDados;

    /** @param string[] $colunasDados */
    public function __construct(string $aba, array $colunasDados, ?GoogleSheetsService $sheets = null)
    {
        $this->aba = $aba;
        $this->colunasDados = $colunasDados;
        $this->sheets = $sheets ?? new GoogleSheetsService();
    }

    /** @return array<int, array<string, mixed>> todos os registros (inclusive inativos). */
    public function listar(): array
    {
        return array_map([$this, 'paraCamelCase'], $this->sheets->lerLinhas($this->aba));
    }

    /** @return array<int, array<string, mixed>> apenas os registros ativos, usados para preencher combos no front-end. */
    public function listarAtivos(): array
    {
        return array_values(array_filter($this->listar(), fn (array $item) => $item['ativo']));
    }

    public function criar(array $dados): array
    {
        $id = $this->sheets->proximoId($this->aba);
        $linha = ['ID' => (string) $id];
        foreach ($this->colunasDados as $coluna) {
            $linha[$coluna] = (string) ($dados[$this->paraCamel($coluna)] ?? '');
        }
        $linha['Ativo'] = $this->boolParaTexto($dados['ativo'] ?? true);

        $this->sheets->inserir($this->aba, $linha);
        return $this->paraCamelCase($linha);
    }

    public function atualizar(int $id, array $dados): array
    {
        $atual = $this->sheets->buscarPorId($this->aba, $id);
        if ($atual === null) {
            throw new RuntimeException('Registro não encontrado.');
        }

        $linha = ['ID' => (string) $id];
        foreach ($this->colunasDados as $coluna) {
            $chave = $this->paraCamel($coluna);
            $linha[$coluna] = array_key_exists($chave, $dados) ? (string) $dados[$chave] : ($atual[$coluna] ?? '');
        }
        $linha['Ativo'] = array_key_exists('ativo', $dados) ? $this->boolParaTexto($dados['ativo']) : ($atual['Ativo'] ?? 'TRUE');

        $this->sheets->atualizar($this->aba, (int) $atual['_linha'], $linha);
        return $this->paraCamelCase($linha);
    }

    public function excluir(int $id): void
    {
        $atual = $this->sheets->buscarPorId($this->aba, $id);
        if ($atual === null) {
            return;
        }
        $this->sheets->excluir($this->aba, (int) $atual['_linha']);
    }

    /**
     * Insere os registros padrão informados, apenas se a aba ainda
     * estiver completamente vazia (sem nenhuma linha de dados).
     * @param array<int, array<string, mixed>> $sementes
     */
    public function semearSeVazio(array $sementes): void
    {
        if (!empty($this->sheets->lerLinhas($this->aba))) {
            return;
        }
        foreach ($sementes as $semente) {
            $this->criar($semente + ['ativo' => true]);
        }
    }

    // ------------------------------------------------------------------

    private function boolParaTexto($valor): string
    {
        if (is_string($valor)) {
            $valor = strtolower(trim($valor));
            return in_array($valor, ['1', 'true', 'sim', 'verdadeiro'], true) ? 'TRUE' : 'FALSE';
        }
        return $valor ? 'TRUE' : 'FALSE';
    }

    /** 'Nome' -> 'nome' ; 'ID Secretaria' -> 'idSecretaria'. */
    private function paraCamel(string $coluna): string
    {
        $partes = preg_split('/\s+/', $coluna);
        $primeiro = strtolower((string) array_shift($partes));
        return $primeiro . implode('', array_map(fn ($p) => ucfirst(strtolower($p)), $partes));
    }

    private function paraCamelCase(array $linha): array
    {
        $resultado = ['id' => (int) ($linha['ID'] ?? 0)];
        foreach ($this->colunasDados as $coluna) {
            $resultado[$this->paraCamel($coluna)] = $linha[$coluna] ?? '';
        }
        $resultado['ativo'] = strtoupper((string) ($linha['Ativo'] ?? 'TRUE')) !== 'FALSE';
        return $resultado;
    }
}
