<?php
/**
 * bootstrap.php
 * Ponto único de inicialização do back-end: autoload do Composer,
 * tratamento de erros em JSON e fuso horário.
 */

declare(strict_types=1);

date_default_timezone_set('America/Porto_Velho');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/services/RepositorioDados.php';
require_once __DIR__ . '/services/GoogleSheetsService.php';
require_once __DIR__ . '/services/VoluntarioService.php';
require_once __DIR__ . '/services/ContratoService.php';
require_once __DIR__ . '/services/DocumentoService.php';
require_once __DIR__ . '/services/ListaSimplesService.php';
require_once __DIR__ . '/src/VoluntarioRepository.php';
require_once __DIR__ . '/src/DocumentoRepository.php';
require_once __DIR__ . '/src/TermoAdesaoService.php';

/**
 * Armazenamento de dados usado por todo o sistema (instância única por
 * requisição). ÚNICO ponto a alterar numa futura migração para MySQL:
 *
 *     return $instancia ??= new MySQLService();
 *
 * (MySQLService deve estender RepositorioDados — ver services/RepositorioDados.php.)
 */
function repositorioDados(): RepositorioDados
{
    static $instancia = null;
    return $instancia ??= new GoogleSheetsService();
}

/** Acrescenta uma linha em logs/app.log (falhas de escrita no log são ignoradas). */
function registrarLog(string $mensagem): void
{
    $diretorio = __DIR__ . '/logs';
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0775, true);
    }
    @file_put_contents($diretorio . '/app.log', '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Converte qualquer erro/exceção não tratada em uma resposta JSON,
 * em vez de deixar o HTML de erro do PHP vazar para a API. O erro
 * também é registrado em logs/app.log.
 */
function configurarTratamentoDeErros(): void
{
    set_exception_handler(function (Throwable $e) {
        registrarLog(sprintf(
            '%s %s -> %s: %s (%s:%d)',
            $_SERVER['REQUEST_METHOD'] ?? '-',
            $_SERVER['REQUEST_URI'] ?? '-',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
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
    // Exigir JSON obriga o navegador a fazer preflight em requisições de
    // outras origens (que a API não libera), bloqueando envios forjados
    // por formulários de outros sites.
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        responderJson(['sucesso' => false, 'mensagem' => 'Envie os dados como application/json.'], 415);
    }

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

/**
 * Responde a preflights (OPTIONS) sem liberar CORS. O front-end é servido
 * pelo mesmo servidor PHP (mesma origem), então não precisa de CORS; não
 * enviar "Access-Control-Allow-Origin: *" impede que outros sites abertos
 * no navegador leiam, alterem ou excluam voluntários pela API local.
 */
function habilitarCors(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
