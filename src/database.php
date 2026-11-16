<?php

/** Consulta somente metadados; não lê registros das tabelas da aplicação. */
function readDatabaseSchema(array $config): array
{
    $driver = $config['driver'];
    if (!class_exists(PDO::class) || !in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException("Instale/habilite a extensão PHP pdo_{$driver} para esta conexão.");
    }

    $dsn = $driver === 'mysql'
        ? "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4"
        : 'pgsql:host=' . quotePostgresDsn($config['host'])
            . ';port=' . $config['port']
            . ';dbname=' . quotePostgresDsn($config['name'])
            . ';connect_timeout=10';

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);

    // PDOException chega à CLI com a mensagem original do driver/banco.
    return $driver === 'mysql'
        ? readMysqlSchema($pdo, $config['name'])
        : readPostgresSchema($pdo, $config['schema']);
}

function quotePostgresDsn(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

function metadataRows(PDO $pdo, string $sql, string $scope): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute([$scope]);

    return $statement->fetchAll();
}

function readMysqlSchema(PDO $pdo, string $database): array
{
    $tables = [];
    foreach (metadataRows($pdo, <<<'SQL'
        SELECT TABLE_NAME AS table_name
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
        SQL, $database) as $row) {
        $tables[$row['table_name']] = ['name' => $row['table_name'], 'columns' => [], 'primaryKey' => []];
    }

    foreach (metadataRows($pdo, <<<'SQL'
        SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name,
               COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable,
               COLUMN_DEFAULT AS column_default
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
        ORDER BY TABLE_NAME, ORDINAL_POSITION
        SQL, $database) as $row) {
        if (isset($tables[$row['table_name']])) {
            $tables[$row['table_name']]['columns'][$row['column_name']] = [
                'type' => normalizeSqlType($row['column_type']),
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => normalizeDefault($row['column_default']),
            ];
        }
    }

    foreach (metadataRows($pdo, <<<'SQL'
        SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME = 'PRIMARY'
        ORDER BY TABLE_NAME, ORDINAL_POSITION
        SQL, $database) as $row) {
        if (isset($tables[$row['table_name']])) {
            $tables[$row['table_name']]['primaryKey'][] = $row['column_name'];
        }
    }

    return $tables;
}

function readPostgresSchema(PDO $pdo, string $schema): array
{
    $exists = metadataRows($pdo, 'SELECT nspname FROM pg_catalog.pg_namespace WHERE nspname = ?', $schema);
    if ($exists === []) {
        throw new RuntimeException('O schema PostgreSQL configurado não existe neste banco. Verifique database.schema.');
    }

    $tables = [];
    foreach (metadataRows($pdo, <<<'SQL'
        SELECT c.relname AS table_name
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = ? AND c.relkind IN ('r', 'p')
        ORDER BY c.relname
        SQL, $schema) as $row) {
        $tables[$row['table_name']] = ['name' => $row['table_name'], 'columns' => [], 'primaryKey' => []];
    }

    foreach (metadataRows($pdo, <<<'SQL'
        SELECT c.relname AS table_name, a.attname AS column_name,
               pg_catalog.format_type(a.atttypid, a.atttypmod) AS column_type,
               CASE WHEN a.attnotnull OR t.typnotnull THEN 'NO' ELSE 'YES' END AS is_nullable,
               CASE WHEN a.attidentity <> '' OR pg_catalog.pg_get_serial_sequence(
                   pg_catalog.quote_ident(n.nspname) || '.' || pg_catalog.quote_ident(c.relname), a.attname
               ) IS NOT NULL THEN NULL
               ELSE pg_catalog.pg_get_expr(d.adbin, d.adrelid) END AS column_default
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
        JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
        LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
        WHERE n.nspname = ? AND c.relkind IN ('r', 'p')
          AND a.attnum > 0 AND NOT a.attisdropped
        ORDER BY c.relname, a.attnum
        SQL, $schema) as $row) {
        $tables[$row['table_name']]['columns'][$row['column_name']] = [
            'type' => normalizePostgresType($row['column_type']),
            'nullable' => $row['is_nullable'] === 'YES',
            'default' => normalizePostgresDefault($row['column_default']),
        ];
    }

    // conkey preserva a ordem de uma chave primária composta.
    foreach (metadataRows($pdo, <<<'SQL'
        SELECT c.relname AS table_name, a.attname AS column_name
        FROM pg_catalog.pg_constraint k
        JOIN pg_catalog.pg_class c ON c.oid = k.conrelid
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        CROSS JOIN LATERAL unnest(k.conkey) WITH ORDINALITY AS key_column(attnum, position)
        JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum = key_column.attnum
        WHERE n.nspname = ? AND k.contype = 'p' AND c.relkind IN ('r', 'p')
        ORDER BY c.relname, key_column.position
        SQL, $schema) as $row) {
        $tables[$row['table_name']]['primaryKey'][] = $row['column_name'];
    }

    return $tables;
}

/** Apenas equivalências diretas; não esconde diferenças como UNSIGNED/JSONB. */
function normalizePostgresType(string $type): string
{
    $type = preg_replace('/^character varying\b/i', 'VARCHAR', $type) ?? $type;
    $type = preg_replace('/^character\b/i', 'CHAR', $type) ?? $type;
    $type = preg_replace('/^timestamp(\(\d+\))? without time zone$/i', 'DATETIME$1', $type) ?? $type;
    $type = preg_replace('/^time(\(\d+\))? without time zone$/i', 'TIME$1', $type) ?? $type;

    return normalizeSqlType($type);
}

function normalizePostgresDefault(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    // Remove casts somente de literais: expressões e funções ficam visíveis.
    if (preg_match("~^('(?:''|[^'])*'|[+-]?(?:\\d+(?:\\.\\d*)?|\\.\\d+)|NULL|true|false)::[a-zA-Z_][a-zA-Z0-9_ .\\[\\](),\"]*$~i", $value, $match)) {
        $value = $match[1];
    }
    if (strcasecmp(trim($value), 'now()') === 0) {
        return 'CURRENT_TIMESTAMP';
    }

    return normalizeDefault($value);
}
