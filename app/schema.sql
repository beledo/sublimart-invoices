CREATE TABLE IF NOT EXISTS hh_documents (
  id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  type VARCHAR(10) NOT NULL,
  number VARCHAR(40) NOT NULL,
  number_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_date CHAR(10) NOT NULL,
  client_name VARCHAR(150) NOT NULL DEFAULT '',
  data LONGTEXT NOT NULL,
  base_cents BIGINT NOT NULL,
  tax_cents BIGINT NOT NULL,
  total_cents BIGINT NOT NULL,
  tax_rate INT NOT NULL,
  pdf_key VARCHAR(72) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision INT NOT NULL DEFAULT 1,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  deleted_at BIGINT NULL,
  expires_at BIGINT NULL,
  UNIQUE KEY hh_number_unique (type,number_key),
  KEY hh_archive (deleted_at,updated_at),
  KEY hh_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hh_sequences (
  id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  type VARCHAR(10) NOT NULL,
  prefix VARCHAR(40) NOT NULL,
  suffix VARCHAR(4) NOT NULL,
  year INT NOT NULL,
  highest BIGINT NOT NULL,
  padding INT NOT NULL,
  last_number VARCHAR(40) NOT NULL,
  updated_at BIGINT NOT NULL,
  KEY hh_series (type,year,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hh_company (
  id VARCHAR(20) PRIMARY KEY,
  data LONGTEXT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hh_clients (
  id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  tax_key VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(150) NOT NULL,
  data LONGTEXT NOT NULL,
  revision INT NOT NULL DEFAULT 1,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE KEY hh_clients_tax_key (tax_key),
  KEY hh_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
