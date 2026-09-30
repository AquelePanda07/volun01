<?php
/**
 * config/google-sheets.php
 *
 * Configuração centralizada da integração com o Google Sheets, que é
 * utilizado como banco de dados TEMPORÁRIO do sistema (ver README.md).
 *
 * NENHUMA credencial deve ficar espalhada pelo código: tudo o que a
 * aplicação precisa para falar com o Google Sheets está aqui e no
 * arquivo de chave da Service Account referenciado por
 * `credentials_path`.
 *
 * Todos os valores podem ser sobrescritos por variáveis de ambiente,
 * o que facilita trocar a planilha (ex.: homologação x produção) sem
 * alterar código.
 *
 * IMPORTANTE: o arquivo apontado por `credentials_path` NUNCA deve ser
 * commitado (já está listado em .gitignore). Veja instruções de como
 * gerá-lo na seção "Configuração do Google Sheets" do README.md.
 */

declare(strict_types=1);

return [
    // ID da planilha (fica entre "/d/" e "/edit" na URL do Google Sheets).
    'spreadsheet_id' => getenv('GOOGLE_SHEETS_SPREADSHEET_ID') ?: '1pTZ9okHYFLq66Wqc6nLr0r8jE9XdTiF3j2kwY-laTg4',

    // Caminho do arquivo JSON da Service Account (baixado do Google Cloud Console).
    // Coloque o arquivo real em: config/credentials/service-account.json
    'credentials_path' => getenv('GOOGLE_SHEETS_CREDENTIALS_PATH') ?: __DIR__ . '/credentials/service-account.json',

    // Escopo de acesso solicitado ao Google (leitura e escrita na planilha).
    'scope' => 'https://www.googleapis.com/auth/spreadsheets',

    // Nomes das abas (tabs) utilizadas pelo sistema. Caso a aba ainda não
    // exista na planilha informada, o GoogleSheetsService a cria
    // automaticamente (com o cabeçalho definido em "colunas" abaixo) na
    // primeira vez que for utilizada.
    'abas' => [
        'voluntarios' => 'Voluntarios',
        'contratos' => 'Contratos',
        'cargos' => 'Cargos',
        'secretarias' => 'Secretarias',
        'locais' => 'Locais',
        'secretarios' => 'Secretarios',
        'documentos' => 'Documentos',
    ],

    // Colunas (cabeçalho da linha 1) de cada aba, na ordem exigida pela
    // especificação do sistema. A ordem aqui é a mesma ordem em que os
    // valores são gravados/lidos pelo GoogleSheetsService.
    'colunas' => [
        'Voluntarios' => [
            'ID', 'Foto', 'Nome', 'CPF', 'RG', 'Orgao Expedidor', 'Data Expedicao RG',
            'Data Nascimento', 'Idade', 'Sexo', 'Escolaridade', 'Estado Civil',
            'CEP', 'Logradouro', 'Numero', 'Complemento', 'Bairro', 'Cidade', 'Estado',
            'Data Cadastro',
        ],
        'Contratos' => [
            'ID', 'ID Voluntario', 'Carga Horaria', 'Cargo', 'Local Prestacao',
            'Data Inicio', 'Data Termino', 'Horario Inicio', 'Horario Termino',
            'Dias Semana', 'Secretaria', 'Nome Secretario', 'Status', 'Data Cadastro',
        ],
        'Cargos' => ['ID', 'Nome', 'Ativo'],
        'Secretarias' => ['ID', 'Nome', 'Sigla', 'Ativo'],
        'Locais' => ['ID', 'Nome', 'Endereco', 'Ativo'],
        'Secretarios' => ['ID', 'ID Secretaria', 'Nome', 'Ativo'],
        // "Usuario Responsavel" é um acréscimo do sistema (não está na lista
        // original da especificação) para manter o histórico de quem gerou
        // cada documento, já que ainda não existe módulo de login.
        'Documentos' => [
            'ID', 'ID Voluntario', 'Tipo Documento', 'Nome Arquivo', 'Caminho Arquivo',
            'Versao', 'Usuario Responsavel', 'Data Geracao',
        ],
    ],
];
