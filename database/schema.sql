-- Controle de Obrigações Martinelli — esquema do banco, versão multi-escritório
-- (MySQL 5.7+ / 8.x e MariaDB 10.4+)
--
-- Importe com:  mysql -u root -p NOME_DO_BANCO < database/schema.sql
-- Depois rode:  php bin/setup.php   (cria o primeiro escritório e o administrador)
--
-- Ideia central: cada ESCRITÓRIO (identificado pelo CNPJ) é uma "ilha". Clientes, modelos de obrigação,
-- departamentos, características, feriados e tarefas sempre carregam o escritorio_id.
-- As PESSOAS (usuarios) são globais e se ligam a um ou mais escritórios por VÍNCULOS, cada um com seu papel.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS login_tentativas;
DROP TABLE IF EXISTS log_tarefas;
DROP TABLE IF EXISTS tarefas;
DROP TABLE IF EXISTS cliente_modelos;
DROP TABLE IF EXISTS cliente_caracteristicas;
DROP TABLE IF EXISTS feriados;
DROP TABLE IF EXISTS modelos;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS caracteristicas;
DROP TABLE IF EXISTS departamentos;
DROP TABLE IF EXISTS vinculos;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS escritorios;

SET FOREIGN_KEY_CHECKS = 1;

-- Escritórios de contabilidade que usam o sistema. O CNPJ (14 caracteres, numérico ou alfanumérico, sem pontuação)
-- é o identificador usado na primeira etapa do login.
CREATE TABLE escritorios (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cnpj       CHAR(14) NOT NULL,
  nome       VARCHAR(150) NOT NULL,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_escritorios_cnpj (cnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pessoas (identidade única: um e-mail e uma senha, mesmo que atenda vários escritórios).
-- dono_plataforma = pode cadastrar novos escritórios (você).
CREATE TABLE usuarios (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome            VARCHAR(120) NOT NULL,
  email           VARCHAR(160) NOT NULL,
  senha_hash      VARCHAR(255) NOT NULL,
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  dono_plataforma TINYINT(1) NOT NULL DEFAULT 0,
  ultimo_login    DATETIME NULL,
  criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem trabalha em qual escritório, e com qual papel.
CREATE TABLE vinculos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED NOT NULL,
  escritorio_id INT UNSIGNED NOT NULL,
  papel         ENUM('admin','contador','colaborador') NOT NULL DEFAULT 'colaborador',
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vinculo (usuario_id, escritorio_id),
  KEY ix_vinculo_esc (escritorio_id),
  CONSTRAINT fk_vinc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_vinc_escritorio FOREIGN KEY (escritorio_id) REFERENCES escritorios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Departamentos de trabalho (Fiscal, Contábil, Pessoal... cada escritório cria os seus).
CREATE TABLE departamentos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  escritorio_id INT UNSIGNED NOT NULL,
  nome          VARCHAR(60) NOT NULL,
  ordem         SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_depto (escritorio_id, nome),
  CONSTRAINT fk_depto_esc FOREIGN KEY (escritorio_id) REFERENCES escritorios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Características que um cliente pode ter (Tem folha, Tem ISS, Tem ICMS, Recebe XML de compras...).
-- Os modelos de obrigação podem exigir uma característica: quem não a tem, não recebe a tarefa.
CREATE TABLE caracteristicas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  escritorio_id INT UNSIGNED NOT NULL,
  nome          VARCHAR(80) NOT NULL,
  ordem         SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_caract (escritorio_id, nome),
  CONSTRAINT fk_caract_esc FOREIGN KEY (escritorio_id) REFERENCES escritorios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empresas atendidas (um registro por CNPJ). inicio/fim (AAAA-MM) limitam os meses em que o cliente gera tarefas.
CREATE TABLE clientes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  escritorio_id   INT UNSIGNED NOT NULL,
  nome            VARCHAR(150) NOT NULL,
  cnpj            CHAR(14) NULL,
  regime          ENUM('MEI','Simples Nacional','Lucro Presumido','Lucro Real') NOT NULL,
  responsavel_id  INT UNSIGNED NULL,
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  inicio          CHAR(7) NULL,
  fim             CHAR(7) NULL,
  obs             VARCHAR(500) NULL,
  criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cliente_cnpj (escritorio_id, cnpj),
  KEY ix_clientes_resp (responsavel_id),
  CONSTRAINT fk_cli_esc FOREIGN KEY (escritorio_id) REFERENCES escritorios (id) ON DELETE CASCADE,
  CONSTRAINT fk_cli_resp FOREIGN KEY (responsavel_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente_caracteristicas (
  cliente_id       INT UNSIGNED NOT NULL,
  caracteristica_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (cliente_id, caracteristica_id),
  CONSTRAINT fk_cc_cli FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_car FOREIGN KEY (caracteristica_id) REFERENCES caracteristicas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelos de obrigação: definem quais tarefas cada cliente recebe e como o vencimento é calculado.
-- regimes e meses são listas separadas por vírgula (ex.: 'Simples Nacional,MEI' e '1,4,7,10').
-- meses vazio = vence todo mês. caracteristica_id vazio = vale para todos os clientes do regime.
-- vigente_desde (AAAA-MM): a obrigação só aparece daquele mês em diante (não bagunça meses passados).
CREATE TABLE modelos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  escritorio_id     INT UNSIGNED NOT NULL,
  departamento_id   INT UNSIGNED NOT NULL,
  nome              VARCHAR(120) NOT NULL,
  regimes           VARCHAR(120) NOT NULL,
  caracteristica_id INT UNSIGNED NULL,
  meses             VARCHAR(40) NOT NULL DEFAULT '',
  venc_tipo         ENUM('dia','nutil','ultimo') NOT NULL DEFAULT 'dia',
  venc_valor        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ajuste            ENUM('prorroga','antecipa') NOT NULL DEFAULT 'prorroga',
  vigente_desde     CHAR(7) NULL,
  observacao        VARCHAR(255) NULL,
  ativo             TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_modelos_esc (escritorio_id),
  CONSTRAINT fk_mod_esc   FOREIGN KEY (escritorio_id)     REFERENCES escritorios (id)     ON DELETE CASCADE,
  CONSTRAINT fk_mod_depto FOREIGN KEY (departamento_id)   REFERENCES departamentos (id)   ON DELETE RESTRICT,
  CONSTRAINT fk_mod_car   FOREIGN KEY (caracteristica_id) REFERENCES caracteristicas (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exceção por cliente: força uma obrigação a aparecer ('incluir') ou sumir ('excluir'),
-- não importa o que o regime e as características dizem.
CREATE TABLE cliente_modelos (
  cliente_id  INT UNSIGNED NOT NULL,
  modelo_id   INT UNSIGNED NOT NULL,
  acao        ENUM('incluir','excluir') NOT NULL,
  PRIMARY KEY (cliente_id, modelo_id),
  CONSTRAINT fk_cm_cli FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_mod FOREIGN KEY (modelo_id)  REFERENCES modelos (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Situação de cada obrigação de cada cliente em cada mês. Só existe linha quando alguém mexeu
-- (o que não tem linha é "pendente").
CREATE TABLE tarefas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  escritorio_id   INT UNSIGNED NOT NULL,
  mes             CHAR(7) NOT NULL,                       -- AAAA-MM do mês de vencimento
  cliente_id      INT UNSIGNED NOT NULL,
  modelo_id       INT UNSIGNED NOT NULL,
  status          ENUM('pendente','andamento','aguardando','concluida','na') NOT NULL DEFAULT 'pendente',
  obs             VARCHAR(1000) NOT NULL DEFAULT '',
  atualizado_por  INT UNSIGNED NULL,
  atualizado_em   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tarefa (mes, cliente_id, modelo_id),
  KEY ix_tarefas_esc_mes (escritorio_id, mes, status),
  CONSTRAINT fk_tar_esc     FOREIGN KEY (escritorio_id)  REFERENCES escritorios (id) ON DELETE CASCADE,
  CONSTRAINT fk_tar_cliente FOREIGN KEY (cliente_id)     REFERENCES clientes (id)    ON DELETE CASCADE,
  CONSTRAINT fk_tar_modelo  FOREIGN KEY (modelo_id)      REFERENCES modelos (id)     ON DELETE CASCADE,
  CONSTRAINT fk_tar_usuario FOREIGN KEY (atualizado_por) REFERENCES usuarios (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico: quem mudou o quê e quando (auditoria).
CREATE TABLE log_tarefas (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  escritorio_id  INT UNSIGNED NOT NULL,
  mes            CHAR(7) NOT NULL,
  cliente_id     INT UNSIGNED NOT NULL,
  modelo_id      INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  status_antes   VARCHAR(12) NOT NULL,
  status_depois  VARCHAR(12) NOT NULL,
  obs            VARCHAR(1000) NOT NULL DEFAULT '',
  criado_em      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_log_tarefa (escritorio_id, mes, cliente_id, modelo_id),
  CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feriados estaduais e municipais de cada escritório (os nacionais, Carnaval e Corpus Christi o sistema já calcula).
CREATE TABLE feriados (
  escritorio_id  INT UNSIGNED NOT NULL,
  data           DATE NOT NULL,
  descricao      VARCHAR(80) NOT NULL,
  PRIMARY KEY (escritorio_id, data),
  CONSTRAINT fk_fer_esc FOREIGN KEY (escritorio_id) REFERENCES escritorios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle de tentativas de login (limita tentativas repetidas).
CREATE TABLE login_tentativas (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(160) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  criado_em  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_login_email (email, criado_em),
  KEY ix_login_ip (ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
