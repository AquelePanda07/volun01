-- ---------------------------------------------------------------------
-- Sistema de Cadastro e Gestão de Voluntários
-- Banco de dados: MySQL/MariaDB (XAMPP)
--
-- Referência da estrutura usada pelo services/MySQLService.php.
-- NÃO é preciso rodar este script: o sistema cria o banco, as tabelas e
-- as colunas faltantes sozinho no primeiro uso. Para copiar os dados da
-- planilha do Google, use scripts/migrar_sheets_para_mysql.php.
--
-- Os campos são VARCHAR (texto) de propósito: guardam os valores
-- exatamente como eram na planilha (datas 'AAAA-MM-DD', 'TRUE'/'FALSE'...).
-- ---------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS sistema_voluntarios
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE sistema_voluntarios;

CREATE TABLE IF NOT EXISTS `cargos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `ativo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `contratos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_voluntario` varchar(255) NOT NULL DEFAULT '',
  `carga_horaria` varchar(255) NOT NULL DEFAULT '',
  `cargo` varchar(255) NOT NULL DEFAULT '',
  `local_prestacao` varchar(255) NOT NULL DEFAULT '',
  `data_inicio` varchar(255) NOT NULL DEFAULT '',
  `data_termino` varchar(255) NOT NULL DEFAULT '',
  `horario_inicio` varchar(255) NOT NULL DEFAULT '',
  `horario_termino` varchar(255) NOT NULL DEFAULT '',
  `dias_semana` varchar(255) NOT NULL DEFAULT '',
  `secretaria` varchar(255) NOT NULL DEFAULT '',
  `nome_secretario` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(255) NOT NULL DEFAULT '',
  `data_cadastro` varchar(255) NOT NULL DEFAULT '',
  `renovado` varchar(255) NOT NULL DEFAULT '',
  `data_renovacao` varchar(255) NOT NULL DEFAULT '',
  `termino_anterior` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_id_voluntario` (`id_voluntario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `documentos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_voluntario` varchar(255) NOT NULL DEFAULT '',
  `tipo_documento` varchar(255) NOT NULL DEFAULT '',
  `nome_arquivo` varchar(255) NOT NULL DEFAULT '',
  `caminho_arquivo` varchar(255) NOT NULL DEFAULT '',
  `versao` varchar(255) NOT NULL DEFAULT '',
  `usuario_responsavel` varchar(255) NOT NULL DEFAULT '',
  `data_geracao` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_id_voluntario` (`id_voluntario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `locais` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `endereco` varchar(255) NOT NULL DEFAULT '',
  `ativo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `secretarias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL DEFAULT '',
  `sigla` varchar(255) NOT NULL DEFAULT '',
  `ativo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `secretarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_secretaria` varchar(255) NOT NULL DEFAULT '',
  `nome` varchar(255) NOT NULL DEFAULT '',
  `ativo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_id_secretaria` (`id_secretaria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `voluntarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `foto` varchar(255) NOT NULL DEFAULT '',
  `nome` varchar(255) NOT NULL DEFAULT '',
  `cpf` varchar(255) NOT NULL DEFAULT '',
  `rg` varchar(255) NOT NULL DEFAULT '',
  `orgao_expedidor` varchar(255) NOT NULL DEFAULT '',
  `data_expedicao_rg` varchar(255) NOT NULL DEFAULT '',
  `data_nascimento` varchar(255) NOT NULL DEFAULT '',
  `idade` varchar(255) NOT NULL DEFAULT '',
  `sexo` varchar(255) NOT NULL DEFAULT '',
  `escolaridade` varchar(255) NOT NULL DEFAULT '',
  `estado_civil` varchar(255) NOT NULL DEFAULT '',
  `cep` varchar(255) NOT NULL DEFAULT '',
  `logradouro` varchar(255) NOT NULL DEFAULT '',
  `numero` varchar(255) NOT NULL DEFAULT '',
  `complemento` varchar(255) NOT NULL DEFAULT '',
  `bairro` varchar(255) NOT NULL DEFAULT '',
  `cidade` varchar(255) NOT NULL DEFAULT '',
  `estado` varchar(255) NOT NULL DEFAULT '',
  `data_cadastro` varchar(255) NOT NULL DEFAULT '',
  `contato` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_cpf` (`cpf`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
