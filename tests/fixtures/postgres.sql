CREATE TABLE accounts (
    tenant_id INTEGER NOT NULL,
    id BIGSERIAL NOT NULL,
    username CHARACTER VARYING(80) NOT NULL DEFAULT 'guest',
    balance NUMERIC(12,2) NOT NULL DEFAULT 100.00,
    enabled INTEGER NOT NULL DEFAULT 0,
    occurred_at TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    created_at TIMESTAMP WITHOUT TIME ZONE,
    PRIMARY KEY (id, tenant_id)
);
CREATE TABLE drift (
    id INTEGER NOT NULL PRIMARY KEY,
    name CHARACTER VARYING(40) DEFAULT 'db',
    only_database TEXT,
    amount INTEGER DEFAULT 100
);
CREATE TABLE database_only (id INTEGER NOT NULL PRIMARY KEY);
CREATE VIEW ignored_view AS SELECT id FROM accounts;
CREATE SCHEMA other_schema;
CREATE TABLE other_schema.ignored_table (id INTEGER);
