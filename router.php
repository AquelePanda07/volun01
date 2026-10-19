<?php
/**
 * router.php
 *
 * Roteador do servidor embutido do PHP (php -S 0.0.0.0:8001 router.php).
 *
 * Sem ele, o servidor embutido entrega QUALQUER arquivo da pasta do
 * projeto — inclusive a chave da Service Account em config/credentials/,
 * os logs e o código-fonte. Como o sistema fica acessível pela rede local,
 * aqui só é liberado o que o navegador realmente precisa:
 *
 *   - páginas .html da raiz
 *   - css/, js/, img/
 *   - fotos em uploads/voluntarios/
 *   - endpoints em api/*.php
 *
 * Todo o resto responde 404.
 */

declare(strict_types=1);

$caminho = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($caminho === '/' || $caminho === '') {
    header('Location: /index.html');
    return true;
}

$permitido = !str_contains($caminho, '..') && !str_contains($caminho, "\0") && (
    preg_match('#^/[\w-]+\.html$#', $caminho)
    || preg_match('#^/(css|js|img)/#', $caminho)
    || preg_match('#^/uploads/voluntarios/[^/]+\.(jpe?g|png|gif|webp)$#i', $caminho)
    || preg_match('#^/api/[\w-]+\.php$#', $caminho)
);

if (!$permitido) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado.';
    return true;
}

// Deixa o servidor embutido servir o arquivo (ou executar o .php da API).
return false;
