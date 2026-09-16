<?php
/**
 * Configuração de acesso ao banco de dados MySQL.
 * Os valores podem ser sobrescritos por variáveis de ambiente
 * (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS), úteis em produção.
 */

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'gestao_voluntarios',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => 'utf8mb4',
];
