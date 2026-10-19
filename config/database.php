<?php
/**
 * config/database.php
 *
 * Escolhe onde o sistema guarda os dados e como conectar ao MySQL.
 *
 *   driver = 'google_sheets'  -> planilha do Google (services/GoogleSheetsService.php) [padrão]
 *   driver = 'mysql'          -> MySQL/MariaDB do XAMPP (services/MySQLService.php)
 *
 * Usuário e senha do MySQL NÃO ficam neste arquivo: são lidos de
 * config/credentials/mysql.json (fora do git, ver .gitignore), no formato
 *
 *     {"usuario": "voluntarios_app", "senha": "..."}
 *
 * Todos os valores podem ser sobrescritos por variáveis de ambiente.
 */

declare(strict_types=1);

$credenciais = [];
$arquivoCredenciais = __DIR__ . '/credentials/mysql.json';
if (is_file($arquivoCredenciais)) {
    $credenciais = json_decode((string) file_get_contents($arquivoCredenciais), true) ?: [];
}

return [
    'driver' => getenv('DB_DRIVER') ?: 'google_sheets',

    'mysql' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'sistema_voluntarios',
        'usuario' => getenv('DB_USUARIO') ?: ($credenciais['usuario'] ?? 'root'),
        'senha' => getenv('DB_SENHA') ?: ($credenciais['senha'] ?? ''),
    ],
];
