<?php
/**
 * Database.php
 * Fábrica de conexão PDO (padrão singleton simples) com o MySQL.
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instancia = null;

    public static function conexao(): PDO
    {
        if (self::$instancia === null) {
            $config = require __DIR__ . '/../config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$instancia = new PDO($dsn, $config['user'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    'Não foi possível conectar ao banco de dados. Verifique se o MySQL está em execução ' .
                    'e se as configurações em config/database.php estão corretas. Detalhe: ' . $e->getMessage()
                );
            }
        }

        return self::$instancia;
    }
}
