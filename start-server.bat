@echo off
setlocal EnableExtensions

REM ---------------------------------------------------------------------
REM start-server.bat
REM Inicia o Sistema de Gestao de Voluntarios localmente, usando o
REM servidor embutido do PHP, e abre o navegador automaticamente.
REM
REM Duplo clique neste arquivo eh a unica coisa necessaria para rodar o
REM sistema no Windows (ver README.md, secao "Como iniciar o sistema").
REM ---------------------------------------------------------------------

title Sistema de Gestao de Voluntarios
cd /d "%~dp0"

echo ===============================================================
echo   Sistema de Gestao de Voluntarios
echo ===============================================================
echo.
echo Verificando se o PHP esta instalado...
echo.

where php >nul 2>nul
if errorlevel 1 (
    echo [ERRO] PHP nao foi encontrado.
    echo.
    echo Instale o PHP e adicione-o ao PATH do Windows.
    echo Depois execute novamente o start-server.bat.
    echo.
    echo Download: https://windows.php.net/download/
    echo.
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('php -r "echo PHP_VERSION;"') do set PHP_VERSION=%%v
echo PHP encontrado (versao %PHP_VERSION%).
echo.

if not exist "vendor\autoload.php" (
    echo [ERRO] As dependencias do Composer nao foram instaladas.
    echo.
    echo Execute "composer install" na pasta do projeto e tente novamente.
    echo.
    pause
    exit /b 1
)

if not exist "config\credentials" mkdir "config\credentials" >nul 2>nul
if not exist "uploads\voluntarios" mkdir "uploads\voluntarios" >nul 2>nul
if not exist "documentos\termos" mkdir "documentos\termos" >nul 2>nul
if not exist "logs" mkdir "logs" >nul 2>nul

set HOST=127.0.0.1
set PORT=8000
set URL=http://localhost:%PORT%

echo Iniciando o servidor PHP embutido em %URL% ...
echo O servidor vai rodar em uma nova janela: feche-a para encerrar o sistema.
echo.

start "Sistema de Gestao de Voluntarios - Servidor PHP (%URL%)" cmd /k php -S %HOST%:%PORT%

REM Aguarda o servidor subir antes de abrir o navegador.
timeout /t 2 /nobreak >nul

start "" "%URL%/index.html"

echo O navegador foi aberto em %URL%
echo Esta janela pode ser fechada normalmente; o servidor continua rodando
echo na outra janela ate que ela seja fechada.
timeout /t 5
exit /b 0
