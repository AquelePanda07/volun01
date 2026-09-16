-- ---------------------------------------------------------------------
-- Sistema de Cadastro e Gestão de Voluntários
-- Banco de dados: MySQL
-- ---------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS gestao_voluntarios
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE gestao_voluntarios;

-- ---------------------------------------------------------------------
-- Tabela: voluntarios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voluntarios (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Dados pessoais
    foto VARCHAR(255) NULL,
    nome VARCHAR(200) NOT NULL,
    cpf VARCHAR(14) NOT NULL,
    rg VARCHAR(20) NOT NULL,
    rg_orgao_expedidor VARCHAR(30) NOT NULL,
    rg_data_expedicao DATE NOT NULL,
    data_nascimento DATE NOT NULL,
    sexo ENUM('masculino','feminino','outro','nao_informar') NOT NULL,
    escolaridade VARCHAR(60) NOT NULL,
    estado_civil ENUM('solteiro','casado','divorciado','viuvo','uniao_estavel') NOT NULL,

    -- Endereço
    cep VARCHAR(9) NOT NULL,
    logradouro VARCHAR(200) NOT NULL,
    numero VARCHAR(20) NOT NULL,
    complemento VARCHAR(100) NULL,
    bairro VARCHAR(100) NOT NULL,
    cidade VARCHAR(100) NOT NULL,
    estado CHAR(2) NOT NULL,

    -- Dados da prestação de serviço
    carga_horaria ENUM('20','30','40') NOT NULL,
    cargo VARCHAR(150) NOT NULL,
    local_prestacao VARCHAR(200) NOT NULL,
    data_inicio DATE NOT NULL,
    data_termino DATE NOT NULL,
    horario_inicio TIME NOT NULL,
    horario_termino TIME NOT NULL,
    dias_semana VARCHAR(100) NOT NULL, -- ex.: "SEG,TER,QUA,QUI,SEX" ou texto livre
    secretaria VARCHAR(150) NOT NULL,
    nome_secretario VARCHAR(200) NOT NULL,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_voluntarios_cpf (cpf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabela: documentos_gerados
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos_gerados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_voluntario INT NOT NULL,
    tipo_documento VARCHAR(100) NOT NULL,      -- ex.: "Termo de Adesão (PDF)" / "Termo de Adesão (DOCX)"
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500),
    versao INT NOT NULL DEFAULT 1,
    usuario_responsavel VARCHAR(150) NOT NULL DEFAULT 'Administrador',
    data_geracao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_voluntario)
        REFERENCES voluntarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_documentos_voluntario ON documentos_gerados(id_voluntario);
