# Sistema de Cadastro e Gestão de Voluntários

Sistema web para cadastro e gestão de voluntários da Prefeitura do Município de
Itapuã do Oeste/RO, com geração automática do **Termo de Adesão ao Serviço
Voluntário (ANEXO V)** em PDF e DOCX a partir dos dados cadastrados.

## Stack

- **Front-end**: HTML5, CSS3 e JavaScript puro (`fetch` para consumir a API).
- **Back-end**: PHP 8 (sem framework), com uma pequena camada de rotas em `api/`.
- **Banco de dados**: **Google Sheets** (planilha usada como banco de dados
  *temporário* — veja a seção [Google Sheets como banco de dados](#3-google-sheets-como-banco-de-dados)).
  A arquitetura foi feita em camadas para permitir, no futuro, substituir o
  Google Sheets por MySQL sem reconstruir a interface (veja
  [Migração futura para MySQL](#4-migração-futura-para-mysql)).
- **Geração de documentos**: [PhpOffice/PhpWord](https://github.com/PHPOffice/PHPWord)
  (DOCX, a partir de um modelo real) e [Dompdf](https://github.com/dompdf/dompdf) (PDF).

## Arquitetura em camadas

```
Interface (HTML/JS)  →  API PHP (api/*.php)  →  Service (services/*.php)  →  GoogleSheetsService  →  Google Sheets
```

- `api/*.php`: endpoints HTTP (rotas), só cuidam de request/response HTTP.
- `src/VoluntarioRepository.php` e `src/DocumentoRepository.php`: camada de
  compatibilidade que expõe a mesma interface usada pelas rotas e junta
  voluntário + contrato num único objeto (como o front-end espera).
- `services/VoluntarioService.php`, `services/ContratoService.php`,
  `services/DocumentoService.php`, `services/ListaSimplesService.php`: regras
  de negócio de cada entidade (validação de CPF único, cálculo de status,
  versionamento de documentos, etc.).
- `services/RepositorioDados.php`: contrato da camada de dados. Os services
  acima dependem **só** dele (nunca do Google Sheets diretamente). Define
  as operações genéricas (`listar`, `buscarPorId`, `inserir`, `atualizar`,
  `excluir`) e os métodos nomeados da especificação (`getVoluntarios()`,
  `getVoluntarioById()`, `createVoluntario()`, `updateVoluntario()`,
  `deleteVoluntario()`, `getContratos()`, `createContrato()`,
  `updateContrato()`, `deleteContrato()`, `getSecretarias()`, `getCargos()`,
  `getLocais()`…).
- `services/GoogleSheetsService.php`: **única** classe do sistema que fala
  diretamente com a Google Sheets API (implementa `RepositorioDados`).
  Identifica registros pelo `ID`, autentica com uma Service Account, cria
  abas/colunas automaticamente se não existirem e mantém as leituras em
  cache durante cada requisição (para não estourar a cota da API).
- `repositorioDados()` em `bootstrap.php`: **único** ponto onde se escolhe o
  armazenamento (hoje, `GoogleSheetsService`).

Nenhuma credencial do Google fica no HTML, CSS ou JavaScript — tudo passa
pelo PHP, que é o único lugar que lê o arquivo de credenciais.

## Estrutura do projeto

```
start-server.bat        Inicia o sistema (PHP embutido + navegador) com duplo clique
index.html, voluntarios.html, cadastro.html, visualizar.html, gerar-termo.html
                         Páginas do front-end (HTML + JS puro)
api/                     Endpoints HTTP: voluntarios, contratos, documentos,
                         cargos, secretarias, locais, secretarios
config/
  google-sheets.php      Configuração centralizada (ID da planilha, abas, colunas)
  credentials/           Chave da Service Account do Google (NÃO versionada)
services/
  RepositorioDados.php     Contrato da camada de dados (base para Google Sheets hoje, MySQL no futuro)
  GoogleSheetsService.php  Comunicação de baixo nível com a Google Sheets API
  VoluntarioService.php    Regras de negócio da aba "Voluntarios"
  ContratoService.php      Regras de negócio da aba "Contratos"
  DocumentoService.php     Regras de negócio da aba "Documentos"
  ListaSimplesService.php  CRUD genérico das abas de apoio (Cargos/Secretarias/Locais/Secretarios)
src/
  VoluntarioRepository.php Facade que junta voluntário + contrato (usado pelas rotas)
  DocumentoRepository.php  Facade sobre DocumentoService
  TermoAdesaoService.php   Geração do Termo de Adesão (PDF/DOCX)
  Validator.php            Validações de servidor (espelham as do front-end)
templates/               Modelo DOCX (anexo_v_modelo.docx) e modelo HTML do PDF (anexo_v_pdf.php)
uploads/voluntarios/     Fotos enviadas no cadastro (criado automaticamente)
documentos/termos/       Termos de Adesão gerados em PDF/DOCX (criado automaticamente)
logs/                    app.log com os erros ocorridos na API (criado automaticamente)
sql/schema.sql           Script MySQL (referência para a futura migração — não usado hoje)
scripts/                 Script utilitário para (re)gerar o modelo DOCX a partir do zero
js/, css/                Front-end (cadastro, listagem, visualização, geração do termo)
```

## 1. Requisitos

- PHP 8.1+ com as extensões `curl`, `mbstring`, `zip`, `dom`, `xml`, `fileinfo`.
- [Composer](https://getcomposer.org/).
- Uma conta Google com acesso para criar um projeto no Google Cloud e uma
  planilha no Google Sheets.

## 2. Instalação

```powershell
# 1) Instalar as dependências PHP
composer install

# 2) Configurar o acesso ao Google Sheets (veja a seção 3 abaixo)

# 3) Iniciar o sistema
start-server.bat
```

Isso abre automaticamente `http://localhost:8000` no navegador. Se preferir
iniciar manualmente: `php -S 127.0.0.1:8000` e acesse
`http://127.0.0.1:8000/index.html`.

## 3. Google Sheets como banco de dados

O Google Sheets é usado como banco de dados **temporário** do sistema. A
planilha utilizada é:

<https://docs.google.com/spreadsheets/d/1pTZ9okHYFLq66Wqc6nLr0r8jE9XdTiF3j2kwY-laTg4/edit>

O ID dessa planilha já vem configurado por padrão em `config/google-sheets.php`
(pode ser sobrescrito pela variável de ambiente `GOOGLE_SHEETS_SPREADSHEET_ID`,
útil para usar uma cópia/planilha de testes).

O sistema fala com o Google Sheets usando a **Sheets API v4** autenticada por
uma **Service Account** (sem nenhuma interação/login do usuário final). Passo
a passo para configurar:

1. Acesse o [Google Cloud Console](https://console.cloud.google.com/) e crie
   (ou selecione) um projeto.
2. Em **APIs e serviços → Biblioteca**, ative a **Google Sheets API**.
3. Em **APIs e serviços → Credenciais → Criar credenciais → Conta de
   serviço**, crie uma Service Account (não precisa de papéis/roles no
   projeto).
4. Abra a Service Account criada → aba **Chaves** → **Adicionar chave →
   Criar nova chave → JSON**. Um arquivo `.json` será baixado.
5. Renomeie esse arquivo para `service-account.json` e coloque-o em:
   ```
   config/credentials/service-account.json
   ```
   Esse arquivo **nunca** deve ser commitado (já está listado no
   `.gitignore`).
6. Abra o arquivo JSON e copie o valor do campo `client_email`
   (algo como `nome@projeto.iam.gserviceaccount.com`).
7. Abra a planilha do Google Sheets, clique em **Compartilhar** e adicione
   esse e-mail com permissão de **Editor**.
8. Pronto. Na primeira vez que o sistema for usado, ele cria automaticamente
   (se ainda não existirem) as abas e os respectivos cabeçalhos descritos
   abaixo — não é necessário criar nada manualmente na planilha.

Se preferir usar outra planilha (ex.: uma cópia para testes), basta trocar o
ID em `config/google-sheets.php` ou na variável de ambiente
`GOOGLE_SHEETS_SPREADSHEET_ID`, e compartilhá-la com o mesmo e-mail da Service
Account.

### Abas (tabs) utilizadas

| Aba | Colunas |
|---|---|
| `Voluntarios` | ID, Foto, Nome, CPF, RG, Orgao Expedidor, Data Expedicao RG, Data Nascimento, Idade, Sexo, Escolaridade, Estado Civil, CEP, Logradouro, Numero, Complemento, Bairro, Cidade, Estado, Data Cadastro |
| `Contratos` | ID, ID Voluntario, Carga Horaria, Cargo, Local Prestacao, Data Inicio, Data Termino, Horario Inicio, Horario Termino, Dias Semana, Secretaria, Nome Secretario, Status, Data Cadastro |
| `Cargos` | ID, Nome, Ativo |
| `Secretarias` | ID, Nome, Sigla, Ativo |
| `Locais` | ID, Nome, Endereco, Ativo |
| `Secretarios` | ID, ID Secretaria, Nome, Ativo |
| `Documentos` | ID, ID Voluntario, Tipo Documento, Nome Arquivo, Caminho Arquivo, Versao, Usuario Responsavel, Data Geracao |

### Decisões de implementação sobre o Google Sheets (não especificadas literalmente)

- **Voluntário + Contrato "achatados" na API**: a especificação separa
  voluntário e contrato em duas abas (para já deixar o modelo pronto para um
  histórico de múltiplos contratos por voluntário), mas a interface hoje
  trabalha com **um contrato vigente por voluntário** (o mais recente). O
  `src/VoluntarioRepository.php` junta os dois numa única resposta, como o
  front-end sempre esperou — nenhuma tela precisou mudar por causa da
  separação em abas.
- **Coluna "Status" da aba Contratos**: nunca é lida como fonte de verdade —
  é sempre recalculada a partir das datas (A_INICIAR/ATIVO/ENCERRADO) tanto no
  PHP quanto no JavaScript. A coluna existe só para quem abrir a planilha
  diretamente conseguir ler o status sem depender do sistema.
- **Coluna "Idade" da aba Voluntarios**: idem — é sempre recalculada a partir
  da Data de Nascimento; nunca é editável pelo usuário.
- **IDs**: como o Google Sheets não tem auto-incremento, o `GoogleSheetsService`
  calcula o próximo ID como `MAIOR ID ATUAL + 1` a cada inserção.
- **Criação automática de abas/cabeçalhos**: se uma aba configurada em
  `config/google-sheets.php` não existir na planilha (ou existir vazia), o
  `GoogleSheetsService` a cria e escreve o cabeçalho automaticamente na
  primeira vez que for usada.
- **Cabeçalhos já existentes são respeitados**: as colunas são localizadas
  pelo nome (sem diferenciar maiúsculas/acentos, ex.: "Versão" = "Versao"),
  em qualquer ordem. Colunas que faltarem são **acrescentadas ao final**,
  nunca sobrescritas, e colunas extras criadas à mão são preservadas nas
  edições.
- **Fotos**: salvas como `uploads/voluntarios/<nome_do_voluntario>_<sufixo único>.<ext>`
  (ex.: `joao_da_silva_6abd4d8d75ec2.jpg`); a planilha guarda só esse caminho.
- **Cargos/Secretarias/Locais/Secretarios**: usados para popular os campos de
  seleção do formulário de cadastro (com endpoints próprios em `api/`). As
  listas `Cargos`, `Secretarias` e `Secretarios` são semeadas automaticamente
  com valores padrão na primeira execução, caso estejam vazias; `Locais` fica
  vazia até a Prefeitura cadastrar os locais pela própria interface (via
  opção "Outro (especificar)" no formulário).

## 4. Migração futura para MySQL

O sistema foi organizado para que, no futuro, o Google Sheets possa ser
substituído por MySQL **sem reconstruir a interface**:

1. Criar `services/MySQLService.php` com `class MySQLService extends RepositorioDados`,
   implementando os 5 métodos abstratos (`listar`, `buscarPorId`, `inserir`,
   `atualizar`, `excluir`). Cada "entidade" (`voluntarios`, `contratos`,
   `cargos`…) vira uma tabela, e os registros usam os mesmos nomes de campo
   do cabeçalho da planilha (ex.: `SELECT nome AS \`Nome\``). Os métodos
   nomeados (`getVoluntarios()` etc.) já vêm prontos da classe base.
2. Trocar `new GoogleSheetsService()` por `new MySQLService()` em
   `repositorioDados()` (`bootstrap.php`).

Nenhum service de domínio, rota (`api/*.php`) ou página do front-end
precisa mudar.

O arquivo `sql/schema.sql` foi mantido no repositório como referência para
essa futura migração (ele não é usado pelo sistema atualmente).

## 5. Sobre o modelo do Termo de Adesão

O modelo **ANEXO V** fornecido pela Prefeitura foi transcrito literalmente em
dois formatos, mantendo cabeçalho, numeração, texto das 9 cláusulas e campos de
assinatura inalterados:

- `templates/anexo_v_modelo.docx` — usado pelo `PhpWord\TemplateProcessor` para
  gerar o **DOCX** (apenas os `${CAMPOS}` são substituídos).
- `templates/anexo_v_pdf.php` — modelo HTML equivalente, usado pelo Dompdf para
  gerar o **PDF**.

Caso a Prefeitura forneça uma nova versão oficial do modelo, edite os dois
arquivos acima mantendo os mesmos nomes de placeholder (ex.: `${NOME_VOLUNTARIO}`,
`${DATA_INICIO}` etc. no DOCX e as chaves equivalentes em minúsculo no HTML).
Para recriar o `anexo_v_modelo.docx` do zero a partir do texto transcrito em
código, rode `php scripts/gerar_modelo_docx.php`.

### Campos preenchidos automaticamente

Nome, CPF, RG, órgão expedidor, data de expedição, idade (calculada a partir da
data de nascimento), estado civil, sexo, escolaridade, endereço, secretaria,
nome do secretário, cargo/local de prestação, datas e horários, dias da semana,
data de emissão e número/ano do Termo. Os campos de **assinatura** nunca são
preenchidos automaticamente.

### Decisões de implementação (não especificadas no modelo original)

- **Número do Termo**: usa o `id` do voluntário (ex.: `Nº 0001/2026`), garantindo
  um número estável independente de quantas vezes o documento seja gerado.
- **Qualificação do(a) Secretário(a)** e a última linha de assinatura são
  derivadas automaticamente do campo "Secretaria" (ex.: "Secretaria Municipal de
  Saúde" → "Secretário(a) Municipal de Saúde"), pois o sistema suporta mais de
  uma secretaria além de Educação.
- **"Usuário responsável pela geração"** (coluna na aba `Documentos`): como
  o sistema não possui um módulo de login, é gravado como `"Administrador"` por
  padrão.
- **Nome físico dos arquivos** em `documentos/termos/`: para o usuário, o
  arquivo baixado sempre se chama `Termo_Adesao_Nome_do_Voluntario.pdf` (ou
  `.docx`), como pedido na especificação. Internamente, porém, o nome físico
  do arquivo em disco inclui o ID do voluntário e o número da versão (ex.:
  `Nome_do_Voluntario_12_v2_pdf.pdf`), para nunca colidir entre voluntários
  com nomes iguais nem sobrescrever versões antigas do histórico de
  documentos.

## 6. Fluxo do sistema

```
Adicionar Voluntário → Preencher dados → Salvar (Google Sheets)
        → Visualizar voluntário → "Gerar Termo de Adesão"
        → Pré-visualização (HTML) → Gerar PDF / DOCX
        → Registro na aba "Documentos" → Histórico de Documentos
```

## 7. Regras de validação aplicadas (front-end e back-end)

- Todos os campos marcados como obrigatórios são exigidos antes de salvar.
- CPF deve ser válido e único.
- Idade é sempre calculada a partir da data de nascimento (nunca digitada).
- Data de término deve ser posterior à data de início, e o período não pode
  ultrapassar 6 meses.
- Horário de término deve ser posterior ao horário de início.
- Geração do Termo é bloqueada se houver dados obrigatórios pendentes, com a
  lista de pendências exibida ao usuário.
- Cada geração (PDF ou DOCX) cria uma nova versão no histórico
  (aba `Documentos`), preservando os arquivos anteriores em disco.

## 8. Solução de problemas

- **"PHP não foi encontrado"** ao rodar `start-server.bat`: instale o PHP e
  adicione-o ao PATH do Windows (a janela fica aberta para você ler a
  mensagem com calma).
- **"Credenciais do Google Sheets não encontradas..."**: siga o passo a passo
  da seção 3 e confirme que o arquivo está em
  `config/credentials/service-account.json`.
- **Qualquer outro erro da API**: os detalhes (rota, mensagem, arquivo e
  linha) ficam registrados em `logs/app.log`.
- **Erro 403 da API do Google**: confirme que a planilha foi compartilhada
  (com permissão de Editor) com o e-mail (`client_email`) que está dentro do
  arquivo JSON da Service Account.
