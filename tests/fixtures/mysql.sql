CREATE TABLE accounts (
    tenant_id INT NOT NULL,
    id BIGINT NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL DEFAULT 'guest',
    balance DECIMAL(12,2) NOT NULL DEFAULT 100.00,
    enabled INT NOT NULL DEFAULT 0,
    occurred_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NULL,
    PRIMARY KEY (id, tenant_id)
);
CREATE TABLE drift (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(40) NULL DEFAULT 'db',
    only_database TEXT NULL,
    amount INT NULL DEFAULT 100
);
CREATE TABLE database_only (id INT NOT NULL PRIMARY KEY);
CREATE VIEW ignored_view AS SELECT id FROM accounts;
