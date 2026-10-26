<?php
/**
 * services/GoogleSheetsService.php
 *
 * Única classe do sistema que efetivamente conversa com a Google Sheets
 * API (REST v4). Implementa o contrato RepositorioDados: todo o restante
 * da aplicação (VoluntarioService, ContratoService, DocumentoService,
 * ListaSimplesService) só enxerga registros identificados por um "ID" e
 * campos identificados pelo nome do cabeçalho — nunca letras de coluna,
 * números de linha, índices internos do Google Sheets ou tokens OAuth2.
 *
 * Autenticação: Service Account (fluxo "JWT Bearer" / RFC 7523), sem
 * qualquer interação do usuário e sem nenhuma credencial exposta ao
 * front-end (a chave privada só é lida aqui, no back-end).
 *
 * Isso mantém a arquitetura em camadas pedida no projeto:
 *
 *     Interface -> API PHP -> Service -> GoogleSheetsService -> Google Sheets
 *
 * Se um dia o Google Sheets for substituído por MySQL, basta criar um
 * "MySQLService" que também estenda RepositorioDados (ver
 * services/RepositorioDados.php) e trocá-lo em repositorioDados(), no
 * bootstrap.php.
 */

declare(strict_types=1);

use Firebase\JWT\JWT;

class GoogleSheetsService extends RepositorioDados
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    private string $spreadsheetId;
    private string $credentialsPath;
    private string $scope;

    /** @var array<string, string> entidade lógica => nome da aba. */
    private array $abas;

    /** @var array<string, string[]> Colunas (cabeçalho) esperadas de cada aba. */
    private array $colunasPorAba;

    /** Cache do token de acesso, válido apenas durante a requisição HTTP atual. */
    private static ?string $tokenCache = null;
    private static int $tokenExpiraEm = 0;

    /**
     * Estrutura de cada aba já verificada nesta requisição:
     * ['sheetId' => int, 'indices' => [nomeColuna => índice 0-based], 'total' => nº de colunas].
     * @var array<string, array<string, mixed>>
     */
    private static array $estruturaPorAba = [];

    /**
     * Linhas lidas de cada aba nesta requisição (evita reler a mesma aba
     * várias vezes, o que estouraria a cota de leituras da API). É
     * invalidado a cada escrita na aba.
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $linhasPorAba = [];

    /**
     * O token e a estrutura das abas também são guardados em disco (pasta
     * cache/), para não repetir login + leitura de metadados/cabeçalho a
     * cada requisição — sem isso, cada chamada à API fazia 4 viagens ao
     * Google. A estrutura expira em poucos minutos, para acompanhar
     * colunas acrescentadas manualmente na planilha.
     */
    private const DIR_CACHE = __DIR__ . '/../cache';
    private const VALIDADE_ESTRUTURA = 300;

    /**
     * As linhas lidas também ficam em disco por pouco tempo: navegar entre
     * as páginas deixa de esperar ~1 s pelo Google a cada tela. Escritas
     * feitas pelo sistema apagam esse cache na hora; edições manuais na
     * planilha aparecem em até VALIDADE_LINHAS segundos. Escritas sempre
     * releem a aba (nunca usam este cache), para não gravar na linha errada
     * caso alguém tenha inserido/removido linhas manualmente.
     */
    private const VALIDADE_LINHAS = 60;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/google-sheets.php';

        $this->spreadsheetId = (string) $config['spreadsheet_id'];
        $this->credentialsPath = (string) $config['credentials_path'];
        $this->scope = (string) $config['scope'];
        $this->abas = $config['abas'];
        $this->colunasPorAba = $config['colunas'];

        if ($this->spreadsheetId === '') {
            throw new RuntimeException('Configure o ID da planilha em config/google-sheets.php.');
        }
    }

    // ------------------------------------------------------------------
    // Implementação de RepositorioDados
    // ------------------------------------------------------------------

    public function listar(string $entidade): array
    {
        return array_map([$this, 'semMetadados'], $this->lerLinhas($entidade));
    }

    public function buscarPorId(string $entidade, int $id): ?array
    {
        $linha = $this->localizarLinha($entidade, $id);
        return $linha ? $this->semMetadados($linha) : null;
    }

    public function inserir(string $entidade, array $dados): array
    {
        $aba = $this->aba($entidade);
        $estrutura = $this->garantirAba($aba);

        if (empty($dados['ID'])) {
            $this->lerLinhas($entidade, true);
            $dados['ID'] = (string) $this->proximoId($entidade);
        }

        $valores = array_fill(0, $estrutura['total'], '');
        foreach ($estrutura['indices'] as $coluna => $indice) {
            $valores[$indice] = $this->paraCelula($dados[$coluna] ?? '');
        }

        $this->chamarApi(
            'POST',
            "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!A1") . ':append',
            ['values' => [$valores], 'majorDimension' => 'ROWS'],
            ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']
        );
        $this->invalidarLinhas($aba);

        $registro = [];
        foreach ($estrutura['indices'] as $coluna => $indice) {
            $registro[$coluna] = $valores[$indice];
        }
        return $registro;
    }

    public function atualizar(string $entidade, int $id, array $dados): void
    {
        $aba = $this->aba($entidade);
        $this->lerLinhas($entidade, true);
        $linha = $this->localizarLinha($entidade, $id);
        if ($linha === null) {
            throw new RuntimeException("Registro {$id} não encontrado na aba \"{$aba}\".");
        }
        $estrutura = $this->garantirAba($aba);

        // Parte dos valores atuais da linha, para preservar colunas extras
        // que alguém tenha criado manualmente na planilha.
        $valores = $linha['_valores'];
        foreach ($estrutura['indices'] as $coluna => $indice) {
            if (array_key_exists($coluna, $dados)) {
                $valores[$indice] = $this->paraCelula($dados[$coluna]);
            }
        }

        $numeroLinha = (int) $linha['_linha'];
        $ultimaColuna = $this->letraColuna($estrutura['total']);
        $this->chamarApi(
            'PUT',
            "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!A{$numeroLinha}:{$ultimaColuna}{$numeroLinha}"),
            ['values' => [array_values($valores)], 'majorDimension' => 'ROWS'],
            ['valueInputOption' => 'RAW']
        );
        $this->invalidarLinhas($aba);
    }

    public function excluir(string $entidade, int $id): void
    {
        $aba = $this->aba($entidade);
        $this->lerLinhas($entidade, true);
        $linha = $this->localizarLinha($entidade, $id);
        if ($linha === null) {
            return;
        }
        $estrutura = $this->garantirAba($aba);
        $numeroLinha = (int) $linha['_linha'];

        $this->chamarApi('POST', "/{$this->spreadsheetId}:batchUpdate", [
            'requests' => [[
                'deleteDimension' => [
                    'range' => [
                        'sheetId' => $estrutura['sheetId'],
                        'dimension' => 'ROWS',
                        'startIndex' => $numeroLinha - 1,
                        'endIndex' => $numeroLinha,
                    ],
                ],
            ]],
        ]);
        // As linhas abaixo sobem uma posição: o cache precisa ser relido.
        $this->invalidarLinhas($aba);
    }

    /** Descarta as linhas guardadas (memória e disco) depois de uma escrita na aba. */
    private function invalidarLinhas(string $aba): void
    {
        $chave = $this->chaveCache($aba);
        unset(self::$linhasPorAba[$chave]);
        @unlink($this->arquivoCache('linhas_' . $chave));
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * Lê todas as linhas com dados de uma aba, já convertidas em arrays
     * associativos [nomeDaColuna => valor]. Linhas totalmente vazias são
     * ignoradas. Cada linha inclui as chaves internas "_linha" (número
     * real da linha na planilha) e "_valores" (células brutas), que
     * nunca saem desta classe.
     *
     * $fresco = true ignora os caches e relê a aba no Google (usado antes
     * de qualquer escrita).
     *
     * @return array<int, array<string, mixed>>
     */
    private function lerLinhas(string $entidade, bool $fresco = false): array
    {
        $aba = $this->aba($entidade);
        $chave = $this->chaveCache($aba);
        if (!$fresco) {
            if (isset(self::$linhasPorAba[$chave])) {
                return self::$linhasPorAba[$chave];
            }
            $emDisco = $this->lerCacheDisco('linhas_' . $chave);
            if ($emDisco !== null && ($emDisco['expiraEm'] ?? 0) > time() && is_array($emDisco['linhas'] ?? null)) {
                return self::$linhasPorAba[$chave] = $emDisco['linhas'];
            }
        }

        $estrutura = $this->garantirAba($aba);
        $ultimaColuna = $this->letraColuna($estrutura['total']);

        $resposta = $this->chamarApi(
            'GET',
            "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!A2:{$ultimaColuna}"),
            null,
            ['valueRenderOption' => 'UNFORMATTED_VALUE']
        );

        $linhas = [];
        $numeroLinha = 1; // a linha 1 é sempre o cabeçalho
        foreach (($resposta['values'] ?? []) as $valores) {
            $numeroLinha++;
            if ($this->linhaVazia($valores)) {
                continue;
            }
            $valores = array_pad($valores, $estrutura['total'], '');
            $linha = ['_linha' => $numeroLinha, '_valores' => $valores];
            foreach ($estrutura['indices'] as $coluna => $indice) {
                $linha[$coluna] = $valores[$indice];
            }
            $linhas[] = $linha;
        }

        $this->gravarCacheDisco('linhas_' . $chave, [
            'linhas' => $linhas,
            'expiraEm' => time() + self::VALIDADE_LINHAS,
        ]);
        return self::$linhasPorAba[$chave] = $linhas;
    }

    private function localizarLinha(string $entidade, int $id): ?array
    {
        foreach ($this->lerLinhas($entidade) as $linha) {
            if ((string) ($linha['ID'] ?? '') === (string) $id) {
                return $linha;
            }
        }
        return null;
    }

    /** Calcula o próximo ID disponível (maior ID atual + 1), já que o Google Sheets não tem auto-incremento. */
    private function proximoId(string $entidade): int
    {
        $maior = 0;
        foreach ($this->lerLinhas($entidade) as $linha) {
            $maior = max($maior, (int) ($linha['ID'] ?? 0));
        }
        return $maior + 1;
    }

    private function semMetadados(array $linha): array
    {
        unset($linha['_linha'], $linha['_valores']);
        return $linha;
    }

    // ------------------------------------------------------------------
    // Provisionamento automático (cria a aba/colunas se não existirem)
    // ------------------------------------------------------------------

    /** Converte a entidade lógica ('voluntarios') no nome da aba configurado ('Voluntarios'). */
    private function aba(string $entidade): string
    {
        $aba = $this->abas[$entidade] ?? null;
        if ($aba === null || !isset($this->colunasPorAba[$aba])) {
            throw new InvalidArgumentException("Entidade \"{$entidade}\" não configurada em config/google-sheets.php.");
        }
        return $aba;
    }

    /**
     * Garante que a aba exista e contenha todas as colunas esperadas.
     *
     * O cabeçalho que já existir na planilha é respeitado: as colunas são
     * localizadas pelo nome (ignorando maiúsculas e acentos), em qualquer
     * ordem, e as que estiverem faltando são ACRESCENTADAS ao final —
     * nunca sobrescritas, para não desalinhar dados já existentes.
     */
    private function garantirAba(string $aba): array
    {
        $chave = $this->chaveCache($aba);
        if (isset(self::$estruturaPorAba[$chave])) {
            return self::$estruturaPorAba[$chave];
        }
        $emDisco = $this->lerCacheDisco('estrutura_' . $chave);
        // Estrutura em cache sem alguma coluna configurada (ex.: coluna nova
        // acrescentada ao sistema) é descartada, senão a coluna seria ignorada.
        $cacheCompleto = $emDisco !== null
            && empty(array_diff($this->colunasPorAba[$aba], array_keys($emDisco['estrutura']['indices'] ?? [])));
        if ($cacheCompleto && ($emDisco['expiraEm'] ?? 0) > time()) {
            return self::$estruturaPorAba[$chave] = $emDisco['estrutura'];
        }

        $sheetId = $this->localizarOuCriarAba($aba);

        $resposta = $this->chamarApi('GET', "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!1:1"), null, [
            'valueRenderOption' => 'UNFORMATTED_VALUE',
        ]);
        $cabecalho = array_map('strval', $resposta['values'][0] ?? []);
        $normalizados = array_map([$this, 'normalizarNomeColuna'], $cabecalho);

        $indices = [];
        $faltando = [];
        foreach ($this->colunasPorAba[$aba] as $coluna) {
            $indice = array_search($this->normalizarNomeColuna($coluna), $normalizados, true);
            if ($indice === false) {
                $faltando[] = $coluna;
            } else {
                $indices[$coluna] = $indice;
            }
        }

        if (!empty($faltando)) {
            foreach ($faltando as $coluna) {
                $indices[$coluna] = count($cabecalho);
                $cabecalho[] = $coluna;
            }
            $this->chamarApi('PUT', "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!A1"), [
                'values' => [$cabecalho], 'majorDimension' => 'ROWS',
            ], ['valueInputOption' => 'RAW']);
        }

        self::$estruturaPorAba[$chave] = [
            'sheetId' => $sheetId,
            'indices' => $indices,
            'total' => count($cabecalho),
        ];
        $this->gravarCacheDisco('estrutura_' . $chave, [
            'expiraEm' => time() + self::VALIDADE_ESTRUTURA,
            'estrutura' => self::$estruturaPorAba[$chave],
        ]);
        return self::$estruturaPorAba[$chave];
    }

    private function localizarOuCriarAba(string $aba): int
    {
        $meta = $this->chamarApi('GET', "/{$this->spreadsheetId}", null, ['fields' => 'sheets.properties']);
        foreach (($meta['sheets'] ?? []) as $sheet) {
            if (($sheet['properties']['title'] ?? null) === $aba) {
                return (int) $sheet['properties']['sheetId'];
            }
        }

        // A aba ainda não existe nesta planilha: cria automaticamente,
        // conforme pedido na especificação ("Criar ou utilizar as
        // seguintes abas").
        $resultado = $this->chamarApi('POST', "/{$this->spreadsheetId}:batchUpdate", [
            'requests' => [['addSheet' => ['properties' => ['title' => $aba]]]],
        ]);
        return (int) $resultado['replies'][0]['addSheet']['properties']['sheetId'];
    }

    /** "Órgão  Expedidor " -> "orgao expedidor" (para aceitar cabeçalhos digitados à mão). */
    private function normalizarNomeColuna(string $nome): string
    {
        $semAcento = strtr(mb_strtolower(trim($nome), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        return (string) preg_replace('/\s+/', ' ', $semAcento);
    }

    private function chaveCache(string $aba): string
    {
        return $this->spreadsheetId . '|' . $aba;
    }

    // ------------------------------------------------------------------
    // Autenticação (Service Account / JWT Bearer)
    // ------------------------------------------------------------------

    private function obterAccessToken(): string
    {
        if (self::$tokenCache !== null && time() < self::$tokenExpiraEm) {
            return self::$tokenCache;
        }
        $emDisco = $this->lerCacheDisco('token_' . $this->credentialsPath);
        if ($emDisco !== null && time() < (int) ($emDisco['expiraEm'] ?? 0)) {
            self::$tokenExpiraEm = (int) $emDisco['expiraEm'];
            return self::$tokenCache = (string) $emDisco['token'];
        }

        if (!is_file($this->credentialsPath)) {
            throw new RuntimeException(
                'Credenciais do Google Sheets não encontradas em "' . $this->credentialsPath . '". ' .
                'Baixe a chave JSON da Service Account no Google Cloud Console, salve nesse caminho e ' .
                'compartilhe a planilha com o e-mail da Service Account (veja o README.md).'
            );
        }

        $credenciais = json_decode((string) file_get_contents($this->credentialsPath), true);
        if (!is_array($credenciais) || empty($credenciais['client_email']) || empty($credenciais['private_key'])) {
            throw new RuntimeException('Arquivo de credenciais do Google Sheets inválido (esperado JSON de Service Account).');
        }

        $agora = time();
        $jwt = JWT::encode([
            'iss' => $credenciais['client_email'],
            'scope' => $this->scope,
            'aud' => self::TOKEN_URL,
            'iat' => $agora,
            'exp' => $agora + 3500,
        ], $credenciais['private_key'], 'RS256');

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erro = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Falha ao autenticar com o Google Sheets: {$erro}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($resposta, true);
        if ($status >= 400 || empty($json['access_token'])) {
            $mensagem = $json['error_description'] ?? $json['error'] ?? $resposta;
            throw new RuntimeException("Falha ao autenticar com o Google Sheets ({$status}): {$mensagem}");
        }

        self::$tokenCache = $json['access_token'];
        self::$tokenExpiraEm = $agora + (int) ($json['expires_in'] ?? 3600) - 30;
        $this->gravarCacheDisco('token_' . $this->credentialsPath, [
            'token' => self::$tokenCache,
            'expiraEm' => self::$tokenExpiraEm,
        ]);

        return self::$tokenCache;
    }

    // ------------------------------------------------------------------
    // HTTP (Google Sheets API v4)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> (protected para permitir simular a API em testes) */
    protected function chamarApi(string $metodo, string $caminho, ?array $corpo = null, array $query = []): array
    {
        $url = self::API_BASE . $caminho;
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->obterAccessToken(),
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($corpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
        }

        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erro = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Falha de comunicação com o Google Sheets: {$erro}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($resposta, true);
        if ($status >= 400) {
            $mensagem = $json['error']['message'] ?? $resposta;
            throw new RuntimeException("Erro na API do Google Sheets ({$status}): {$mensagem}");
        }

        return is_array($json) ? $json : [];
    }

    // ------------------------------------------------------------------
    // Cache em disco (entre requisições)
    // ------------------------------------------------------------------

    private function arquivoCache(string $chave): string
    {
        return self::DIR_CACHE . '/sheets_' . md5($chave) . '.json';
    }

    private function lerCacheDisco(string $chave): ?array
    {
        $arquivo = $this->arquivoCache($chave);
        if (!is_file($arquivo)) {
            return null;
        }
        $dados = json_decode((string) @file_get_contents($arquivo), true);
        return is_array($dados) ? $dados : null;
    }

    private function gravarCacheDisco(string $chave, array $dados): void
    {
        if (!is_dir(self::DIR_CACHE) && !@mkdir(self::DIR_CACHE, 0775, true)) {
            return; // sem cache em disco o sistema só fica mais lento, não quebra
        }
        @file_put_contents($this->arquivoCache($chave), json_encode($dados), LOCK_EX);
    }

    // ------------------------------------------------------------------
    // Utilitários
    // ------------------------------------------------------------------

    /** Valores novos são gravados como texto (RAW): evita fórmulas e conversões automáticas do Sheets. */
    private function paraCelula($valor): string
    {
        return $valor === null ? '' : (string) $valor;
    }

    private function linhaVazia(array $valores): bool
    {
        foreach ($valores as $valor) {
            if (trim((string) $valor) !== '') {
                return false;
            }
        }
        return true;
    }

    /** Converte um índice de coluna baseado em 1 (1, 2, 3...) em letra(s) do Google Sheets (A, B, ..., Z, AA...). */
    private function letraColuna(int $indiceBaseUm): string
    {
        $letras = '';
        while ($indiceBaseUm > 0) {
            $resto = ($indiceBaseUm - 1) % 26;
            $letras = chr(65 + $resto) . $letras;
            $indiceBaseUm = intdiv($indiceBaseUm - 1, 26);
        }
        return $letras;
    }
}
