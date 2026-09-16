<?php
/**
 * bootstrap.php
 * Ponto único de inicialização do back-end: autoload do Composer,
 * tratamento de erros em JSON e fuso horário.
 */

declare(strict_types=1);

date_default_timezone_set('America/Porto_Velho');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/VoluntarioRepository.php';
require_once __DIR__ . '/src/DocumentoRepository.php';
require_once __DIR__ . '/src/TermoAdesaoService.php';

/**
 * Converte qualquer erro/exceção não tratada em uma resposta JSON,
 * em vez de deixar o HTML de erro do PHP vazar para a API.
 */
function configurarTratamentoDeErros(): void
{
    set_exception_handler(function (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });

    set_error_handler(function ($severidade, $mensagem, $arquivo, $linha) {
        throw new ErrorException($mensagem, 0, $severidade, $arquivo, $linha);
    });
}

/** Lê o corpo JSON da requisição atual e devolve como array associativo. */
function lerJsonDaRequisicao(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || $bruto === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($dados)) {
        throw new InvalidArgumentException('Corpo da requisição inválido: JSON malformado.');
    }
    return $dados;
}

/** Envia uma resposta JSON padronizada e encerra a execução. */
function responderJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Habilita CORS simples (útil quando o front-end roda em outra porta). */
function habilitarCors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
