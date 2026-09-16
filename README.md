# Sistema de Cadastro e Gestão de Voluntários

Sistema web para cadastro e gestão de voluntários da Prefeitura do Município de
Itapuã do Oeste/RO, com geração automática do **Termo de Adesão ao Serviço
Voluntário (ANEXO V)** em PDF e DOCX a partir dos dados cadastrados.

## Stack

- **Front-end**: HTML5, CSS3 e JavaScript puro (`fetch` para consumir a API).
- **Back-end**: PHP 8 (sem framework), com uma pequena camada de rotas em `api/`.
- **Banco de dados**: MySQL.
- **Geração de documentos**: [PhpOffice/PhpWord](https://github.com/PHPOffice/PHPWord)
  (DOCX, a partir de um modelo real) e [Dompdf](https://github.com/dompdf/dompdf) (PDF).

## Estrutura do projeto

```
api/                 Endpoints HTTP (voluntarios.php, documentos.php)
config/database.php  Configuração de conexão com o MySQL
src/                 Classes PHP (Database, Repositories, Validator, TermoAdesaoService)
sql/schema.sql        Script de criação do banco (tabelas voluntarios e documentos_gerados)
templates/           Modelo DOCX (anexo_v_modelo.docx) e modelo HTML do PDF (anexo_v_pdf.php)
scripts/              Script utilitário para (re)gerar o modelo DOCX a partir do zero
storage/              Fotos enviadas e documentos gerados (criado automaticamente)
js/, css/, *.html      Front-end (cadastro, listagem, visualização, geração do termo)
```

## 1. Requisitos

- PHP 8.1+ com as extensões `pdo_mysql`, `mbstring`, `zip`, `dom`, `xml`, `curl`, `fileinfo`.
- MySQL 5.7+/8 (ou o MySQL que acompanha o XAMPP).
- [Composer](https://getcomposer.org/).

## 2. Instalação

```powershell
# 1) Instalar as dependências PHP
composer install

# 2) Criar o banco e as tabelas
mysql -u root < sql/schema.sql
# (ou importe sql/schema.sql pelo phpMyAdmin)

# 3) Ajustar credenciais do banco, se necessário
#    Por padrão usa host=127.0.0.1, porta=3306, usuário=root, senha vazia
#    (valores padrão do XAMPP). Pode sobrescrever com variáveis de ambiente:
#    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS

# 4) Iniciar o servidor (opção simples, sem precisar do Apache do XAMPP)
php -S 127.0.0.1:8000
```

Depois é só acessar `http://127.0.0.1:8000/index.html`.

Se preferir usar o Apache do XAMPP: copie/link a pasta do projeto para
`C:\xampp\htdocs\volun01` e acesse `http://localhost/volun01/index.html`
(o MySQL do XAMPP também precisa estar em execução).

## 3. Sobre o modelo do Termo de Adesão

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
- **"Usuário responsável pela geração"** (coluna em `documentos_gerados`): como
  o sistema não possui um módulo de login, é gravado como `"Administrador"` por
  padrão.

## 4. Fluxo do sistema

```
Adicionar Voluntário → Preencher dados → Salvar (MySQL)
        → Visualizar voluntário → "Gerar Termo de Adesão"
        → Pré-visualização (HTML) → Gerar PDF / DOCX
        → Registro em `documentos_gerados` → Histórico de Documentos
```

## 5. Regras de validação aplicadas (front-end e back-end)

- Todos os campos marcados como obrigatórios são exigidos antes de salvar.
- CPF deve ser válido e único.
- Idade é sempre calculada a partir da data de nascimento (nunca digitada).
- Data de término deve ser posterior à data de início, e o período não pode
  ultrapassar 6 meses.
- Horário de término deve ser posterior ao horário de início.
- Geração do Termo é bloqueada se houver dados obrigatórios pendentes, com a
  lista de pendências exibida ao usuário.
- Cada geração (PDF ou DOCX) cria uma nova versão no histórico
  (`documentos_gerados`), preservando os arquivos anteriores em disco.
