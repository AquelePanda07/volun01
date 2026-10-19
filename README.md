# Sistema de Cadastro e Gestão de Voluntários

Sistema web para cadastro e gestão de voluntários da Prefeitura do Município de
Itapuã do Oeste/RO, com geração automática do **Termo de Adesão ao Serviço
Voluntário (ANEXO V)** em PDF e DOCX a partir dos dados cadastrados.

## Stack

- **Front-end**: HTML5, CSS3 e JavaScript puro (`fetch` para consumir a API).
- **Back-end**: PHP 8 (sem framework), com uma pequena camada de rotas em `api/`.
- **Banco de dados**: **MySQL/MariaDB do XAMPP** (veja [MySQL](#4-mysql-banco-de-dados-atual)).
  O Google Sheets, usado antes como banco temporário, continua disponível como
  alternativa (veja [Google Sheets](#3-google-sheets-banco-de-dados-anterior--alternativo)).
- **Servidor**: Apache do XAMPP na porta 8001, acessível pela rede local
  (veja [Servidor na rede local](#5-servidor-na-rede-local-xampp)).
- **Geração de documentos**: [PhpOffice/PhpWord](https://github.com/PHPOffice/PHPWord)
  (DOCX, a partir de um modelo real) e [Dompdf](https://github.com/dompdf/dompdf) (PDF).

## Arquitetura em camadas

```
Interface (HTML/JS)  →  API PHP (api/*.php)  →  Service (services/*.php)  →  MySQLService        →  MySQL
                                                                        (ou GoogleSheetsService →  Google Sheets)
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
- `services/MySQLService.php`: a mesma interface sobre MySQL (cada entidade é
  uma tabela; cria banco/tabelas/colunas faltantes automaticamente).
- `repositorioDados()` em `bootstrap.php`: **único** ponto onde se escolhe o
  armazenamento, conforme o `driver` em `config/database.php`.

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
  database.php           Escolha do banco (mysql | google_sheets) e conexão com o MySQL
  google-sheets.php      ID da planilha e definição das entidades/colunas (usada pelos dois bancos)
  credentials/           Senha do MySQL (mysql.json) e chave do Google (NÃO versionadas)
services/
  RepositorioDados.php     Contrato da camada de dados (base para MySQL e Google Sheets)
  MySQLService.php         Acesso ao MySQL (banco atual)
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
sql/schema.sql           Estrutura das tabelas MySQL (referência; o sistema cria sozinho)
scripts/                 Migração Sheets -> MySQL e (re)geração do modelo DOCX
router.php               Bloqueia arquivos internos quando se usa o servidor embutido do PHP
js/, css/                Front-end (cadastro, listagem, visualização, geração do termo)
```

## 1. Requisitos

- [XAMPP](https://www.apachefriends.org/) (Apache + PHP 8.1+ + MySQL/MariaDB).
  O PHP precisa das extensões `curl`, `mbstring`, `zip`, `dom`, `xml`,
  `fileinfo` e `pdo_mysql`.
- [Composer](https://getcomposer.org/).

## 2. Instalação

```powershell
# 1) Instalar as dependências PHP
composer install

# 2) Ligar o MySQL e configurar o banco (veja a seção 4)

# 3) Configurar o Apache do XAMPP (veja a seção 5)
```

Para testes rápidos sem o Apache: `npm run dev` (ou `start-server.bat`), que
usa o servidor embutido do PHP com o `router.php`. Não use junto com o
Apache: os dois ocupam a porta 8001.

## 3. Google Sheets (banco de dados anterior / alternativo)

Antes do MySQL, o Google Sheets era o banco de dados **temporário** do
sistema. Para voltar a usá-lo, troque `driver` para `google_sheets` em
`config/database.php` (ou defina a variável de ambiente `DB_DRIVER`). A
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
  listas `Cargos`, `Secretarias`, `Secretarios` e `Locais` são semeadas
  automaticamente com valores padrão na primeira execução, caso estejam
  vazias (`Locais`: EMEI Pequeno Príncipe, EMEF Dr. Custódio, Creche
  Pequeninos de Cristo, EMEF Sossego da Mamãe e EMEF Cecília Meireles).
  Outros locais podem ser informados pela opção "Outro (especificar)" do
  formulário, ou acrescentados direto na aba `Locais` da planilha.

## 4. MySQL (banco de dados atual)

O sistema usa o MySQL/MariaDB do XAMPP, no banco `sistema_voluntarios`
(o banco antigo `gestao_voluntarios`, de uma versão anterior, não é usado).

- **Conexão**: `config/database.php` (host, porta, nome do banco). Usuário e
  senha ficam em `config/credentials/mysql.json` (fora do git):
  `{"usuario": "voluntarios_app", "senha": "..."}`.
- **Estrutura**: cada entidade é uma tabela (`voluntarios`, `contratos`,
  `cargos`, `secretarias`, `locais`, `secretarios`, `documentos`) e cada
  coluna do cabeçalho vira uma coluna snake_case (`ID Voluntario` →
  `id_voluntario`). Banco, tabelas e colunas faltantes são criados
  automaticamente no primeiro uso; `sql/schema.sql` é só referência.
- **Valores como texto**: os campos são `VARCHAR` e guardam os valores
  exatamente como na planilha (datas `AAAA-MM-DD`, `TRUE`/`FALSE`), para que
  as regras de negócio funcionem igual nos dois bancos.
- **Segurança**: o MySQL aceita conexões só deste PC (`bind-address="127.0.0.1"`
  em `C:\xampp\mysql\bin\my.ini`) e o sistema usa um usuário próprio, com
  acesso apenas ao banco `sistema_voluntarios`.

### Configurando do zero (outro computador)

1. Ligue o MySQL pelo XAMPP Control Panel (ou como serviço, seção 5).
2. Crie o usuário do sistema (no phpMyAdmin → aba SQL, ou no Shell do XAMPP
   com `mysql -u root`), trocando `SENHA_FORTE`:
   ```sql
   CREATE USER 'voluntarios_app'@'localhost' IDENTIFIED BY 'SENHA_FORTE';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
     ON sistema_voluntarios.* TO 'voluntarios_app'@'localhost';
   CREATE USER 'voluntarios_app'@'127.0.0.1' IDENTIFIED BY 'SENHA_FORTE';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
     ON sistema_voluntarios.* TO 'voluntarios_app'@'127.0.0.1';
   ```
3. Crie `config/credentials/mysql.json` com esse usuário e senha.
4. (Opcional) Copie os dados da planilha do Google, mantendo os mesmos IDs:
   ```powershell
   php scripts/migrar_sheets_para_mysql.php
   ```
   O script só grava se as tabelas estiverem vazias (use `--substituir` para
   apagar e copiar de novo), faz tudo numa transação e confere campo a campo
   no final. A planilha não é alterada.

### Backup

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root sistema_voluntarios > backup_voluntarios.sql
```

Guarde também as pastas `uploads/` (fotos) e `documentos/` (termos gerados).
Para restaurar: `C:\xampp\mysql\bin\mysql.exe -u root sistema_voluntarios < backup_voluntarios.sql`.

### Planilha

O botão **Baixar Planilha** do painel gera um CSV (abre no Excel) com os
voluntários e o contrato mais recente de cada um (`api/planilha.php`).

## 5. Servidor na rede local (XAMPP)

O Apache do XAMPP serve o sistema na porta **8001** (bloco "Sistema de Gestao
de Voluntarios" em `C:\xampp\apache\conf\extra\httpd-vhosts.conf`). Esse
bloco só libera páginas, `css/`, `js/`, `img/`, fotos e `api/*.php`: chave do
Google, senha do MySQL, logs e código respondem 404.

Para ligar Apache e MySQL automaticamente com o Windows (PowerShell **como
Administrador**, uma vez só):

```powershell
C:\xampp\apache\bin\httpd.exe -k install -n Apache2.4
C:\xampp\mysql\bin\mysqld.exe --install mysql --defaults-file=C:\xampp\mysql\bin\my.ini
sc.exe config Apache2.4 start= auto
sc.exe config mysql start= auto
net start mysql
net start Apache2.4
New-NetFirewallRule -DisplayName "Sistema Voluntarios 8001" -Direction Inbound -Protocol TCP -LocalPort 8001 -Action Allow -Profile Any
```

Acesse em `http://localhost:8001` neste PC e em `http://<IP deste PC>:8001`
nos demais (o IP aparece em `ipconfig`). O sistema **não tem login**: não o
exponha à internet.

## 6. Sobre o modelo do Termo de Adesão

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

## 7. Fluxo do sistema

```
Adicionar Voluntário → Preencher dados → Salvar (Google Sheets)
        → Visualizar voluntário → "Gerar Termo de Adesão"
        → Pré-visualização (HTML) → Gerar PDF / DOCX
        → Registro na aba "Documentos" → Histórico de Documentos
```

## 8. Regras de validação aplicadas (front-end e back-end)

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

## 9. Solução de problemas

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
