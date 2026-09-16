<?php
/**
 * VoluntarioRepository.php
 * Acesso a dados da tabela `voluntarios` e conversão entre o formato
 * usado pelo front-end (camelCase) e as colunas do banco (snake_case).
 */

declare(strict_types=1);

class VoluntarioRepository
{
    private PDO $pdo;
    private string $dirFotos;

    public function __construct()
    {
        $this->pdo = Database::conexao();
        $this->dirFotos = __DIR__ . '/../storage/fotos';
        if (!is_dir($this->dirFotos)) {
            mkdir($this->dirFotos, 0775, true);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM voluntarios ORDER BY nome ASC');
        return array_map([$this, 'paraCamelCase'], $stmt->fetchAll());
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM voluntarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();
        return $linha ? $this->paraCamelCase($linha) : null;
    }

    public function existeCpf(string $cpf, ?int $idIgnorar = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM voluntarios WHERE cpf = :cpf';
        $params = ['cpf' => $cpf];
        if ($idIgnorar !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $idIgnorar;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function criar(array $dados): array
    {
        $foto = $this->salvarFotoSeNecessario($dados['foto'] ?? null);

        $colunas = $this->paraSnakeCase($dados);
        $colunas['foto'] = $foto;

        $campos = array_keys($colunas);
        $sql = 'INSERT INTO voluntarios (' . implode(', ', $campos) . ') VALUES (' .
            implode(', ', array_map(fn($c) => ":$c", $campos)) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($colunas);

        return $this->buscarPorId((int) $this->pdo->lastInsertId());
    }

    public function atualizar(int $id, array $dados): array
    {
        $atual = $this->buscarPorId($id);
        if ($atual === null) {
            throw new RuntimeException('Voluntário não encontrado.');
        }

        // Mantém a foto atual se nenhuma nova foto (base64) foi enviada.
        if (!empty($dados['foto']) && str_starts_with((string) $dados['foto'], 'data:')) {
            $dados['foto'] = $this->salvarFotoSeNecessario($dados['foto']);
        } else {
            unset($dados['foto']);
        }

        $colunas = $this->paraSnakeCase($dados);
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($colunas)));

        $stmt = $this->pdo->prepare("UPDATE voluntarios SET $sets WHERE id = :id");
        $colunas['id'] = $id;
        $stmt->execute($colunas);

        return $this->buscarPorId($id);
    }

    public function excluir(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM voluntarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Decodifica uma imagem base64 (data URL) e salva em storage/fotos. */
    private function salvarFotoSeNecessario(?string $foto): ?string
    {
        if (!$foto) {
            return null;
        }
        if (!str_starts_with($foto, 'data:')) {
            // já é um caminho existente (ex.: mantido de uma edição anterior)
            return $foto;
        }

        if (!preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,(.+)$/', $foto, $m)) {
            throw new InvalidArgumentException('Formato de imagem inválido para a foto do voluntário.');
        }
        $extensao = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $binario = base64_decode($m[2]);
        if ($binario === false) {
            throw new InvalidArgumentException('Não foi possível decodificar a foto enviada.');
        }

        $nomeArquivo = uniqid('foto_', true) . '.' . $extensao;
        $caminhoAbsoluto = $this->dirFotos . '/' . $nomeArquivo;
        file_put_contents($caminhoAbsoluto, $binario);

        return 'storage/fotos/' . $nomeArquivo;
    }

    /** Converte as chaves do array de snake_case (banco) para camelCase (front-end). */
    private function paraCamelCase(array $linha): array
    {
        $resultado = [];
        foreach ($linha as $chave => $valor) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $chave))));
            $resultado[$camel] = $valor;
        }
        $resultado['idade'] = !empty($linha['data_nascimento']) ? Validator::calcularIdade($linha['data_nascimento']) : null;
        return $resultado;
    }

    /** Converte as chaves relevantes de camelCase (front-end) para snake_case (banco). */
    private function paraSnakeCase(array $dados): array
    {
        $mapaColunas = [
            'foto', 'nome', 'cpf', 'rg', 'rgOrgaoExpedidor', 'rgDataExpedicao', 'dataNascimento',
            'sexo', 'escolaridade', 'estadoCivil', 'cep', 'logradouro', 'numero', 'complemento',
            'bairro', 'cidade', 'estado', 'cargaHoraria', 'cargo', 'localPrestacao', 'dataInicio',
            'dataTermino', 'horarioInicio', 'horarioTermino', 'diasSemana', 'secretaria', 'nomeSecretario',
        ];

        $resultado = [];
        foreach ($mapaColunas as $camel) {
            if (!array_key_exists($camel, $dados)) {
                continue;
            }
            $snake = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $camel));
            $valor = $dados[$camel];
            if ($camel === 'diasSemana' && is_array($valor)) {
                $valor = implode(',', $valor);
            }
            $resultado[$snake] = $valor;
        }
        return $resultado;
    }
}
