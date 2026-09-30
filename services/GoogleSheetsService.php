<?php
/**
 * services/GoogleSheetsService.php
 *
 * Única classe do sistema que efetivamente conversa com a Google Sheets
 * API (REST v4). Todo o restante da aplicação (VoluntarioService,
 * ContratoService, DocumentoService, ListaSimplesService) só enxerga
 * "linhas" identificadas por um "ID" e colunas identificadas pelo nome
 * do cabeçalho — nunca letras de coluna, índices internos do Google
 * Sheets ou tokens OAuth2.
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
 * "MySQLService" com os mesmos métodos públicos desta classe.
 */

declare(strict_types=1);

use Firebase\JWT\JWT;

class GoogleSheetsService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    private string $spreadsheetId;
    private string $credentialsPath;
    private string $scope;

    /** @var array<string, string[]> Colunas (cabeçalho) esperadas de cada aba. */
    private array $colunasPorAba;

    /** Cache do token de acesso, válido apenas durante a requisição HTTP atual. */
    private static ?string $tokenCache = null;
    private static int $tokenExpiraEm = 0;

    /** Cache do sheetId numérico e da verificação de cabeçalho, por aba. */
    private static array $sheetIdPorAba = [];
    private static array $abasVerificadas = [];

    public function __construct()
    {
        $config = require __DIR__ . '/../config/google-sheets.php';

        $this->spreadsheetId = (string) $config['spreadsheet_id'];
        $this->credentialsPath = (string) $config['credentials_path'];
        $this->scope = (string) $config['scope'];
        $this->colunasPorAba = $config['colunas'];

        if ($this->spreadsheetId === '') {
            throw new RuntimeException('Configure o ID da planilha em config/google-sheets.php.');
        }
    }

    // ------------------------------------------------------------------
    // API pública (usada pelos Services de domínio)
    // ------------------------------------------------------------------

    /**
     * Lê todas as linhas com dados de uma aba, já convertidas em arrays
     * associativos [nomeDaColuna => valor]. Linhas totalmente vazias são
     * ignoradas. Cada linha inclui a chave interna "_linha" com o número
     * real da linha na planilha (necessário para updates/deletes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function lerLinhas(string $aba): array
    {
        $colunas = $this->garantirAba($aba);
        $ultimaColuna = $this->letraColuna(count($colunas));

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
            $linha = ['_linha' => $numeroLinha];
            foreach ($colunas as $indice => $nomeColuna) {
                $linha[$nomeColuna] = $valores[$indice] ?? '';
            }
            $linhas[] = $linha;
        }
        return $linhas;
    }

    /** Busca a primeira linha cuja coluna "ID" seja igual ao valor informado. */
    public function buscarPorId(string $aba, $id): ?array
    {
        foreach ($this->lerLinhas($aba) as $linha) {
            if ((string) ($linha['ID'] ?? '') === (string) $id) {
                return $linha;
            }
        }
        return null;
    }

    /** Retorna todas as linhas cuja coluna informada seja igual ao valor dado. */
    public function buscarTodosPor(string $aba, string $coluna, $valor): array
    {
        return array_values(array_filter(
            $this->lerLinhas($aba),
            fn (array $linha) => (string) ($linha[$coluna] ?? '') === (string) $valor
        ));
    }

    /** Calcula o próximo ID disponível (maior ID atual + 1) para uma aba. */
    public function proximoId(string $aba): int
    {
        $maior = 0;
        foreach ($this->lerLinhas($aba) as $linha) {
            $maior = max($maior, (int) ($linha['ID'] ?? 0));
        }
        return $maior + 1;
    }

    /**
     * Insere uma nova linha ao final da aba.
     *
     * @param array<string, mixed> $dados [nomeDaColuna => valor]
     * @return array<string, mixed> a linha gravada, incluindo "_linha"
     */
    public function inserir(string $aba, array $dados): array
    {
        $colunas = $this->garantirAba($aba);
        $valores = $this->ordenarPorColunas($colunas, $dados);

        $resultado = $this->chamarApi(
            'POST',
            "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!A1") . ':append',
            ['values' => [$valores], 'majorDimension' => 'ROWS'],
            ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']
        );

        $numeroLinha = $this->extrairNumeroLinha((string) ($resultado['updates']['updatedRange'] ?? ''));

        $linha = ['_linha' => $numeroLinha];
        foreach ($colunas as $indice => $nome) {
            $linha[$nome] = $valores[$indice];
        }
        return $linha;
    }

    /**
     * Atualiza (sobrescreve) uma linha existente, identificada pelo seu
     * número real na planilha (use o valor de "_linha" retornado por
     * lerLinhas()/buscarPorId()/inserir()).
     *
     * @param array<string, mixed> $dados [nomeDaColuna => valor]
     */
    public function atualizar(string $aba, int $numeroLinha, array $dados): void
    {
        $colunas = $this->garantirAba($aba);
        $valores = $this->ordenarPorColunas($colunas, $dados);
        $ultimaColuna = $this->letraColuna(count($colunas));

        $this->chamarApi(
            'PUT',
            "/{$this->spreadsheetId}/values/" . rawurlencode("'{$aba}'!A{$numeroLinha}:{$ultimaColuna}{$numeroLinha}"),
            ['values' => [$valores], 'majorDimension' => 'ROWS'],
            ['valueInputOption' => 'RAW']
        );
    }

    /** Remove definitivamente uma linha da planilha (desloca as linhas abaixo para cima). */
    public function excluir(string $aba, int $numeroLinha): void
    {
        $this->garantirAba($aba);
        $sheetId = self::$sheetIdPorAba[$this->chaveCache($aba)] ?? $this->localizarOuCriarAba($aba);

        $this->chamarApi('POST', "/{$this->spreadsheetId}:batchUpdate", [
            'requests' => [[
                'deleteDimension' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'dimension' => 'ROWS',
                        'startIndex' => $numeroLinha - 1,
                        'endIndex' => $numeroLinha,
                    ],
                ],
            ]],
        ]);
    }

    // ------------------------------------------------------------------
    // Provisionamento automático (cria a aba/cabeçalho se não existirem)
    // ------------------------------------------------------------------

    /** Garante que a aba exista e tenha o cabeçalho correto; devolve a lista de colunas. */
    private function garantirAba(string $aba): array
    {
        $colunas = $this->colunasPorAba[$aba] ?? null;
        if ($colunas === null) {
            throw new InvalidArgumentException("Aba \"{$aba}\" não configurada em config/google-sheets.php.");
        }

        $chave = $this->chaveCache($aba);
        if (isset(self::$abasVerificadas[$chave])) {
            return $colunas;
        }

        self::$sheetIdPorAba[$chave] = $this->localizarOuCriarAba($aba);
        $this->garantirCabecalho($aba, $colunas);
        self::$abasVerificadas[$chave] = true;

        return $colunas;
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

    private function garantirCabecalho(string $aba, array $colunas): void
    {
        $ultimaColuna = $this->letraColuna(count($colunas));
        $intervalo = "'{$aba}'!A1:{$ultimaColuna}1";

        $atual = $this->chamarApi('GET', "/{$this->spreadsheetId}/values/" . rawurlencode($intervalo), null, [
            'valueRenderOption' => 'UNFORMATTED_VALUE',
        ]);
        $cabecalhoAtual = $atual['values'][0] ?? [];

        if ($cabecalhoAtual === $colunas) {
            return;
        }

        $this->chamarApi('PUT', "/{$this->spreadsheetId}/values/" . rawurlencode($intervalo), [
            'values' => [$colunas], 'majorDimension' => 'ROWS',
        ], ['valueInputOption' => 'RAW']);
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

        return self::$tokenCache;
    }

    // ------------------------------------------------------------------
    // HTTP (Google Sheets API v4)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function chamarApi(string $metodo, string $caminho, ?array $corpo = null, array $query = []): array
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
    // Utilitários
    // ------------------------------------------------------------------

    /** @param string[] $colunas */
    private function ordenarPorColunas(array $colunas, array $dados): array
    {
        $valores = [];
        foreach ($colunas as $nome) {
            $valor = $dados[$nome] ?? '';
            $valores[] = $valor === null ? '' : (string) $valor;
        }
        return $valores;
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

    private function extrairNumeroLinha(string $intervaloAtualizado): int
    {
        if (preg_match('/![A-Z]+(\d+)/', $intervaloAtualizado, $m)) {
            return (int) $m[1];
        }
        throw new RuntimeException('Não foi possível determinar em qual linha os dados foram inseridos na planilha.');
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
