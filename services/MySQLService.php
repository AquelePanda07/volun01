<?php
/**
 * services/MySQLService.php
 *
 * Implementação de RepositorioDados sobre MySQL/MariaDB (o do XAMPP).
 * Substitui o GoogleSheetsService sem que nenhum service de domínio,
 * rota da API ou página do front-end precise mudar:
 *
 *     Interface -> API PHP -> Service -> MySQLService -> MySQL
 *
 * Mapeamento planilha -> banco:
 *   - cada entidade ('voluntarios', 'contratos'...) é uma tabela de mesmo nome;
 *   - cada coluna do cabeçalho vira uma coluna snake_case sem acentos
 *     ('ID Voluntario' -> id_voluntario, 'Orgao Expedidor' -> orgao_expedidor);
 *   - as colunas continuam sendo as definidas em config/google-sheets.php
 *     ("colunas"), que é a definição única dos campos de cada entidade.
 *
 * Os valores são guardados como texto (VARCHAR), exatamente como eram na
 * planilha (datas 'AAAA-MM-DD', booleanos 'TRUE'/'FALSE'...), e devolvidos
 * sempre como string — assim o comportamento dos services é idêntico ao
 * da versão com Google Sheets.
 *
 * Assim como o GoogleSheetsService criava abas e cabeçalhos faltantes, este
 * service cria o banco, as tabelas e as colunas que ainda não existirem na
 * primeira vez que forem usados.
 */

declare(strict_types=1);

class MySQLService extends RepositorioDados
{
    /** @var array<string, string> entidade lógica => nome da aba (cabeçalhos em config/google-sheets.php). */
    private array $abas;

    /** @var array<string, string[]> aba => colunas (nomes do cabeçalho). */
    private array $colunasPorAba;

    private ?PDO $pdo = null;

    /** @var array<string, string> Configuração de conexão (config/database.php, chave "mysql"). */
    private array $conexao;

    /** Tabelas já verificadas/criadas nesta requisição. @var array<string, true> */
    private static array $tabelasProntas = [];

    public function __construct()
    {
        $sheets = require __DIR__ . '/../config/google-sheets.php';
        $this->abas = $sheets['abas'];
        $this->colunasPorAba = $sheets['colunas'];
        $this->conexao = (require __DIR__ . '/../config/database.php')['mysql'];
    }

    // ------------------------------------------------------------------
    // Implementação de RepositorioDados
    // ------------------------------------------------------------------

    public function listar(string $entidade): array
    {
        $tabela = $this->tabela($entidade);
        $linhas = $this->pdo()->query("SELECT * FROM `{$tabela}` ORDER BY `id`")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $linha) => $this->paraRegistro($entidade, $linha), $linhas);
    }

    public function buscarPorId(string $entidade, int $id): ?array
    {
        $tabela = $this->tabela($entidade);
        $stmt = $this->pdo()->prepare("SELECT * FROM `{$tabela}` WHERE `id` = ?");
        $stmt->execute([$id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha ? $this->paraRegistro($entidade, $linha) : null;
    }

    public function buscarTodosPor(string $entidade, string $campo, $valor): array
    {
        $tabela = $this->tabela($entidade);
        if (!in_array($campo, $this->campos($entidade), true)) {
            return parent::buscarTodosPor($entidade, $campo, $valor);
        }
        $coluna = self::nomeColuna($campo);
        $stmt = $this->pdo()->prepare("SELECT * FROM `{$tabela}` WHERE `{$coluna}` = ? ORDER BY `id`");
        $stmt->execute([(string) $valor]);
        return array_map(
            fn (array $linha) => $this->paraRegistro($entidade, $linha),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function inserir(string $entidade, array $dados): array
    {
        $tabela = $this->tabela($entidade);

        $colunas = [];
        $valores = [];
        foreach ($this->campos($entidade) as $campo) {
            if ($campo === 'ID') {
                if (!empty($dados['ID'])) {
                    $colunas[] = '`id`';
                    $valores[] = (int) $dados['ID'];
                }
                continue;
            }
            $colunas[] = '`' . self::nomeColuna($campo) . '`';
            $valores[] = $this->paraTexto($dados[$campo] ?? '');
        }

        $marcadores = implode(', ', array_fill(0, count($valores), '?'));
        $this->pdo()
            ->prepare("INSERT INTO `{$tabela}` (" . implode(', ', $colunas) . ") VALUES ({$marcadores})")
            ->execute($valores);

        $id = !empty($dados['ID']) ? (int) $dados['ID'] : (int) $this->pdo()->lastInsertId();
        return $this->buscarPorId($entidade, $id) ?? [];
    }

    public function atualizar(string $entidade, int $id, array $dados): void
    {
        $tabela = $this->tabela($entidade);
        if ($this->buscarPorId($entidade, $id) === null) {
            throw new RuntimeException("Registro {$id} não encontrado na tabela \"{$tabela}\".");
        }

        $atribuicoes = [];
        $valores = [];
        foreach ($this->campos($entidade) as $campo) {
            if ($campo === 'ID' || !array_key_exists($campo, $dados)) {
                continue;
            }
            $atribuicoes[] = '`' . self::nomeColuna($campo) . '` = ?';
            $valores[] = $this->paraTexto($dados[$campo]);
        }
        if (empty($atribuicoes)) {
            return;
        }

        $valores[] = $id;
        $this->pdo()
            ->prepare("UPDATE `{$tabela}` SET " . implode(', ', $atribuicoes) . ' WHERE `id` = ?')
            ->execute($valores);
    }

    public function excluir(string $entidade, int $id): void
    {
        $tabela = $this->tabela($entidade);
        $this->pdo()->prepare("DELETE FROM `{$tabela}` WHERE `id` = ?")->execute([$id]);
    }

    // ------------------------------------------------------------------
    // Utilitários públicos (usados também pelo script de migração)
    // ------------------------------------------------------------------

    /** @return string[] entidades configuradas ('voluntarios', 'contratos'...). */
    public function entidades(): array
    {
        return array_keys($this->abas);
    }

    /** @return string[] nomes dos campos da entidade, como no cabeçalho da planilha ('ID', 'Nome'...). */
    public function campos(string $entidade): array
    {
        $aba = $this->abas[$entidade] ?? null;
        if ($aba === null || !isset($this->colunasPorAba[$aba])) {
            throw new InvalidArgumentException("Entidade \"{$entidade}\" não configurada em config/google-sheets.php.");
        }
        return $this->colunasPorAba[$aba];
    }

    /** Conexão PDO (cria o banco na primeira vez, se ainda não existir). */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $c = $this->conexao;
        $opcoes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $dsnServidor = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";

        try {
            $this->pdo = new PDO("{$dsnServidor};dbname={$c['database']}", $c['usuario'], $c['senha'], $opcoes);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1049) { // 1049 = banco não existe
                throw new RuntimeException(
                    'Não foi possível conectar ao MySQL (' . $e->getMessage() . '). ' .
                    'Verifique se o MySQL do XAMPP está ligado e os dados em config/database.php.'
                );
            }
            $pdo = new PDO($dsnServidor, $c['usuario'], $c['senha'], $opcoes);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$c['database']}`");
            $this->pdo = $pdo;
        }

        return $this->pdo;
    }

    /** 'ID Voluntario' -> 'id_voluntario', 'Orgão Expedidor' -> 'orgao_expedidor'. */
    public static function nomeColuna(string $campo): string
    {
        $semAcento = strtr(mb_strtolower(trim($campo), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $semAcento), '_');
    }

    // ------------------------------------------------------------------
    // Provisionamento automático (cria tabela/colunas se não existirem)
    // ------------------------------------------------------------------

    /** Nome da tabela da entidade, garantindo que ela exista com todas as colunas. */
    private function tabela(string $entidade): string
    {
        $campos = $this->campos($entidade);
        if (isset(self::$tabelasProntas[$entidade])) {
            return $entidade;
        }

        $pdo = $this->pdo();
        $existentes = $pdo->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS ' .
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($entidade)
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($existentes)) {
            $definicoes = ['`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'];
            $indices = [];
            foreach ($campos as $campo) {
                if ($campo === 'ID') {
                    continue;
                }
                $coluna = self::nomeColuna($campo);
                $definicoes[] = "`{$coluna}` VARCHAR(255) NOT NULL DEFAULT ''";
                // Chaves de ligação (id_voluntario, id_secretaria...) e CPF são usados em buscas.
                if (str_starts_with($coluna, 'id_') || $coluna === 'cpf') {
                    $indices[] = "KEY `idx_{$coluna}` (`{$coluna}`)";
                }
            }
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$entidade}` (\n  " . implode(",\n  ", array_merge($definicoes, $indices)) . "\n)" .
                ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            // Colunas novas na configuração são acrescentadas ao final, nunca removidas.
            foreach ($campos as $campo) {
                $coluna = self::nomeColuna($campo);
                if (!in_array($coluna, $existentes, true)) {
                    $pdo->exec("ALTER TABLE `{$entidade}` ADD COLUMN `{$coluna}` VARCHAR(255) NOT NULL DEFAULT ''");
                }
            }
        }

        self::$tabelasProntas[$entidade] = true;
        return $entidade;
    }

    // ------------------------------------------------------------------
    // Conversões
    // ------------------------------------------------------------------

    /** Linha do banco (snake_case) -> registro com os nomes do cabeçalho ('ID', 'Nome'...), tudo como string. */
    private function paraRegistro(string $entidade, array $linha): array
    {
        $registro = [];
        foreach ($this->campos($entidade) as $campo) {
            $registro[$campo] = $this->paraTexto($linha[self::nomeColuna($campo)] ?? '');
        }
        return $registro;
    }

    private function paraTexto($valor): string
    {
        return $valor === null ? '' : (string) $valor;
    }
}
