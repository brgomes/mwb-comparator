<?php

/**
 * Compara banco.sql x model.mwb
 *
 * Compara:
 *   - tabelas
 *   - colunas
 *   - tipo de dados (incluindo tamanho/precisão/UNSIGNED)
 *   - NULL / NOT NULL
 *   - DEFAULT
 *   - PRIMARY KEY
 *
 * Ignora:
 *   - índices secundários
 *   - nomes de índices
 *   - FOREIGN KEY
 *   - constraints
 *   - colunas de auditoria:
 *       created_at
 *       updated_at
 *       created_by
 *       updated_by
 *
 * Uso:
 *   php index.php nome
 *
 * Os arquivos nome.sql, nome.mwb e nome.txt
 * devem estar na mesma pasta.
 */

if (PHP_SAPI !== 'cli') {
    exit("Execute este arquivo pela CLI.\n");
}

if ($argc !== 2) {
    exit("Uso: php index.php nome\n");
}

$name = trim($argv[1]);

if ($name === '' || preg_match('~[\\/:*?"<>|]~', $name)) {
    exit(
        "ERRO: informe um nome válido para os arquivos.\n" .
        "Exemplo: php index.php sobgestao\n"
    );
}

$sqlFile = __DIR__ . DIRECTORY_SEPARATOR . $name . '.sql';
$mwbFile = __DIR__ . DIRECTORY_SEPARATOR . $name . '.mwb';

$reportFile = basename($name . '.txt');
$reportPath = __DIR__ . DIRECTORY_SEPARATOR . $reportFile;

if (!is_file($sqlFile)) {
    fail("Arquivo SQL não encontrado: {$name}.sql");
}

if (!is_file($mwbFile)) {
    fail("Arquivo MWB não encontrado: {$name}.mwb");
}

$db = parseSqlDump($sqlFile);
$model = parseMwb($mwbFile);

$report = compareSchemas($db, $model);

$output = renderTextReport($report);

echo $output . PHP_EOL;

$ok = @file_put_contents(
    $reportPath,
    $output . PHP_EOL
);

if ($ok === false) {
    fail(
        "não foi possível criar {$reportFile} " .
        "(permissão de escrita)."
    );
}

echo PHP_EOL;
echo "Relatório TXT salvo na raiz do projeto: {$reportFile}" . PHP_EOL;

// Diferenças são o resultado da comparação, não um erro de execução.
exit(0);


/*
|--------------------------------------------------------------------------
| ERRO
|--------------------------------------------------------------------------
*/

function fail(string $message): never
{
    echo "ERRO: {$message}" . PHP_EOL;
    exit(1);
}


/*
|--------------------------------------------------------------------------
| SQL
|--------------------------------------------------------------------------
*/

function parseSqlDump(string $file): array
{
    $sql = file_get_contents($file);

    if ($sql === false) {
        fail("não foi possível ler o arquivo SQL.");
    }

    $tables = [];

    /*
     * Captura cada CREATE TABLE.
     */
    preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-zA-Z0-9_]+)[`"]?\s*\((.*?)\)\s*(?:ENGINE|;)/is',
        $sql,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $tableName = $match[1];
        $body = $match[2];

        $columns = [];
        $primaryKey = [];

        $lines = splitSqlDefinitions($body);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            /*
             * PRIMARY KEY
             */
            if (preg_match(
                '/^PRIMARY\s+KEY\s*\((.*?)\)/i',
                $line,
                $pkMatch
            )) {
                preg_match_all(
                    '/[`"]?([a-zA-Z0-9_]+)[`"]?/',
                    $pkMatch[1],
                    $pkColumns
                );

                $primaryKey = $pkColumns[1] ?? [];

                continue;
            }

            /*
             * Ignora índices secundários.
             */
            if (preg_match(
                '/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+/i',
                $line
            )) {
                continue;
            }

            /*
             * Ignora FOREIGN KEY.
             */
            if (preg_match(
                '/^(?:CONSTRAINT\s+[`"]?[^`"\s]+[`"]?\s+)?FOREIGN\s+KEY/i',
                $line
            )) {
                continue;
            }

            /*
             * Ignora constraints.
             */
            if (preg_match(
                '/^(?:CONSTRAINT|CHECK)\s+/i',
                $line
            )) {
                continue;
            }

            /*
             * Coluna.
             */
            if (!preg_match(
                '/^[`"]?([a-zA-Z0-9_]+)[`"]?\s+(.+)$/s',
                $line,
                $columnMatch
            )) {
                continue;
            }

            $columnName = $columnMatch[1];
            $definition = trim($columnMatch[2]);

            $column = parseSqlColumnDefinition($definition);

            $columns[$columnName] = $column;
        }

        $tables[$tableName] = [
            'name' => $tableName,
            'columns' => $columns,
            'primaryKey' => $primaryKey,
        ];
    }

    return $tables;
}


/**
 * Divide as definições do CREATE TABLE respeitando parênteses
 * e strings.
 */
function splitSqlDefinitions(string $body): array
{
    $definitions = [];
    $current = '';

    $depth = 0;
    $quote = null;
    $length = strlen($body);

    for ($i = 0; $i < $length; $i++) {
        $char = $body[$i];

        if ($quote !== null) {
            $current .= $char;

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $body[++$i];
                continue;
            }

            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $current .= $char;
            continue;
        }

        if ($char === '(') {
            $depth++;
            $current .= $char;
            continue;
        }

        if ($char === ')') {
            $depth--;
            $current .= $char;
            continue;
        }

        if ($char === ',' && $depth === 0) {
            $definitions[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $definitions[] = trim($current);
    }

    return $definitions;
}


/**
 * Analisa a definição de uma coluna SQL.
 */
function parseSqlColumnDefinition(string $definition): array
{
    $type = '';
    $nullable = true;
    $default = null;

    /*
     * Tipo:
     * VARCHAR(191)
     * DECIMAL(12,2)
     * ENUM('A','B')
     * etc.
     */
    if (preg_match(
        '/^([a-zA-Z]+)(\s*\([^)]*\))?(?:\s+UNSIGNED)?/i',
        $definition,
        $typeMatch
    )) {
        $type = $typeMatch[1];

        if (!empty($typeMatch[2])) {
            $type .= $typeMatch[2];
        }

        if (preg_match(
            '/\bUNSIGNED\b/i',
            substr($definition, strlen($typeMatch[0]))
        )) {
            $type .= ' UNSIGNED';
        } elseif (preg_match('/\bUNSIGNED\b/i', $definition)) {
            $type .= ' UNSIGNED';
        }
    }

    /*
     * NOT NULL / NULL
     */
    if (preg_match('/\bNOT\s+NULL\b/i', $definition)) {
        $nullable = false;
    } elseif (preg_match('/\bNULL\b/i', $definition)) {
        $nullable = true;
    }

    /*
     * DEFAULT.
     */
    if (preg_match(
        '/\bDEFAULT\s+((?:\'(?:\\\\.|[^\'])*\')|(?:"(?:\\\\.|[^"])*")|[^\s,]+)/i',
        $definition,
        $defaultMatch
    )) {
        $default = $defaultMatch[1];
    }

    return [
        'type' => normalizeSqlType($type),
        'nullable' => $nullable,
        'default' => normalizeDefault($default),
    ];
}


/*
|--------------------------------------------------------------------------
| MWB
|--------------------------------------------------------------------------
*/

function parseMwb(string $file): array
{
    $zip = new ZipArchive();

    if ($zip->open($file) !== true) {
        fail("não foi possível abrir o arquivo MWB.");
    }

    $xml = $zip->getFromName('document.mwb.xml');

    $zip->close();

    if ($xml === false) {
        fail("document.mwb.xml não encontrado dentro do arquivo MWB.");
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();

    if (!$dom->loadXML($xml)) {
        fail("não foi possível interpretar document.mwb.xml.");
    }

    $xpath = new DOMXPath($dom);

    $objects = [];

    /*
     * Primeiro indexamos todos os objetos pelo id.
     */
    foreach ($xpath->query('//*[@id]') as $node) {
        $id = $node->getAttribute('id');

        if ($id !== '') {
            $objects[$id] = $node;
        }
    }

    $tables = [];

    /*
     * As tabelas do Workbench são db.mysql.Table.
     */
    foreach (
        $xpath->query('//*[@struct-name="db.mysql.Table"]')
        as $tableNode
    ) {
        $tableName = getDirectValue($xpath, $tableNode, 'name');

        if ($tableName === null || trim($tableName) === '') {
            continue;
        }

        $tableName = trim($tableName);

        $columns = [];

        /*
         * columns é um value que contém objetos
         * db.mysql.Column.
         */
        $columnsContainer = getDirectValueNode(
            $xpath,
            $tableNode,
            'columns'
        );

        if ($columnsContainer !== null) {
            foreach (
                $xpath->query(
                    './value[@type="object" and @struct-name="db.mysql.Column"]',
                    $columnsContainer
                ) as $columnNode
            ) {
                $columnName = getDirectValue(
                    $xpath,
                    $columnNode,
                    'name'
                );

                if ($columnName === null || trim($columnName) === '') {
                    continue;
                }

                $columnName = trim($columnName);

                $columns[$columnName] = parseMwbColumn(
                    $xpath,
                    $columnNode
                );
            }
        }

        /*
         * PRIMARY KEY.
         */
        $primaryKey = [];

        $indicesContainer = getDirectValueNode(
            $xpath,
            $tableNode,
            'indices'
        );

        if ($indicesContainer !== null) {
            foreach (
                $xpath->query(
                    './value[@type="object" and @struct-name="db.mysql.Index"]',
                    $indicesContainer
                ) as $indexNode
            ) {
                $isPrimary = getDirectValue(
                    $xpath,
                    $indexNode,
                    'isPrimary'
                );

                $indexType = getDirectValue(
                    $xpath,
                    $indexNode,
                    'indexType'
                );

                if (
                    (string) $isPrimary === '1' ||
                    strtoupper((string) $indexType) === 'PRIMARY'
                ) {
                    $indexColumnsContainer = getDirectValueNode(
                        $xpath,
                        $indexNode,
                        'columns'
                    );

                    if ($indexColumnsContainer !== null) {
                        foreach (
                            $xpath->query(
                                './value[@type="object" and @struct-name="db.mysql.IndexColumn"]',
                                $indexColumnsContainer
                            ) as $indexColumnNode
                        ) {
                            $refId = null;

                            foreach (
                                $xpath->query(
                                    './link[@key="referencedColumn"]',
                                    $indexColumnNode
                                ) as $link
                            ) {
                                $refId = trim($link->textContent);
                                break;
                            }

                            if ($refId !== null && isset($objects[$refId])) {
                                $pkColumnName = getDirectValue(
                                    $xpath,
                                    $objects[$refId],
                                    'name'
                                );

                                if (
                                    $pkColumnName !== null &&
                                    $pkColumnName !== ''
                                ) {
                                    $primaryKey[] = $pkColumnName;
                                }
                            }
                        }
                    }
                }
            }
        }

        $tables[$tableName] = [
            'name' => $tableName,
            'columns' => $columns,
            'primaryKey' => $primaryKey,
        ];
    }

    return $tables;
}


/**
 * Analisa uma coluna do MWB.
 */
function parseMwbColumn(
    DOMXPath $xpath,
    DOMElement $columnNode
): array {
    $simpleType = null;
    $userType = null;

    foreach (
        $xpath->query('./link[@key="simpleType"]', $columnNode)
        as $link
    ) {
        $simpleType = trim($link->textContent);
        break;
    }

    foreach (
        $xpath->query('./link[@key="userType"]', $columnNode)
        as $link
    ) {
        $userType = trim($link->textContent);
        break;
    }

    $explicitParams = getDirectValue(
        $xpath,
        $columnNode,
        'datatypeExplicitParams'
    );

    $length = getDirectValue(
        $xpath,
        $columnNode,
        'length'
    );

    $precision = getDirectValue(
        $xpath,
        $columnNode,
        'precision'
    );

    $scale = getDirectValue(
        $xpath,
        $columnNode,
        'scale'
    );

    $default = getDirectValue(
        $xpath,
        $columnNode,
        'defaultValue'
    );

    $defaultValueIsNull = getDirectValue(
        $xpath,
        $columnNode,
        'defaultValueIsNull'
    );

    $isNotNull = getDirectValue(
        $xpath,
        $columnNode,
        'isNotNull'
    );

    $flags = getDirectValueList(
        $xpath,
        $columnNode,
        'flags'
    );

    $type = buildMwbType(
        $simpleType,
        $userType,
        $explicitParams,
        $length,
        $precision,
        $scale,
        $flags
    );

    /*
     * defaultValueIsNull indica que não há default definido.
     */
    if ((string) $defaultValueIsNull === '1') {
        $default = null;
    }

    return [
        'type' => normalizeSqlType($type),
        'nullable' => ((string) $isNotNull !== '1'),
        'default' => normalizeDefault($default),
    ];
}


/**
 * Constrói o tipo SQL a partir das informações do Workbench.
 */
function buildMwbType(
    ?string $simpleType,
    ?string $userType,
    ?string $explicitParams,
    ?string $length,
    ?string $precision,
    ?string $scale,
    array $flags
): string {
    /*
     * Tipos definidos pelo usuário.
     */
    if (
        $userType !== null &&
        str_ends_with(
            $userType,
            'com.mysql.rdbms.mysql.userdatatype.boolean'
        )
    ) {
        return 'TINYINT(1)';
    }

    if (
        $userType !== null &&
        str_ends_with(
            $userType,
            'com.mysql.rdbms.mysql.userdatatype.integer'
        )
    ) {
        return 'INT';
    }

    /*
     * Tipo simples.
     */
    $type = '';

    if ($simpleType !== null && $simpleType !== '') {
        $parts = explode('.', $simpleType);
        $type = strtoupper(end($parts));
    }

    $map = [
        'BIGINT' => 'BIGINT',
        'INT' => 'INT',
        'INTEGER' => 'INT',
        'SMALLINT' => 'SMALLINT',
        'MEDIUMINT' => 'MEDIUMINT',
        'TINYINT' => 'TINYINT',
        'DECIMAL' => 'DECIMAL',
        'NUMERIC' => 'DECIMAL',
        'FLOAT' => 'FLOAT',
        'DOUBLE' => 'DOUBLE',
        'DOUBLE_PRECISION' => 'DOUBLE',
        'VARCHAR' => 'VARCHAR',
        'CHAR' => 'CHAR',
        'TEXT' => 'TEXT',
        'TINYTEXT' => 'TINYTEXT',
        'MEDIUMTEXT' => 'MEDIUMTEXT',
        'LONGTEXT' => 'LONGTEXT',
        'BINARY' => 'BINARY',
        'VARBINARY' => 'VARBINARY',
        'BLOB' => 'BLOB',
        'TINYBLOB' => 'TINYBLOB',
        'MEDIUMBLOB' => 'MEDIUMBLOB',
        'LONGBLOB' => 'LONGBLOB',
        'DATE' => 'DATE',
        'TIME' => 'TIME',
        'DATETIME' => 'DATETIME',
        'DATETIME_F' => 'DATETIME',
        'TIMESTAMP' => 'TIMESTAMP',
        'TIMESTAMP_F' => 'TIMESTAMP',
        'YEAR' => 'YEAR',
        'JSON' => 'JSON',
        'ENUM' => 'ENUM',
        'SET' => 'SET',
    ];

    if (isset($map[$type])) {
        $type = $map[$type];
    }

    /*
     * ENUM / SET.
     */
    if (
        in_array($type, ['ENUM', 'SET'], true) &&
        $explicitParams !== null &&
        trim($explicitParams) !== ''
    ) {
        $params = normalizeMwbExplicitParams(
            $explicitParams,
            $type
        );

        $type .= '(' . $params . ')';
    }

    /*
     * VARCHAR / CHAR / BINARY / VARBINARY.
     */
    elseif (
        in_array(
            $type,
            ['VARCHAR', 'CHAR', 'BINARY', 'VARBINARY'],
            true
        )
    ) {
        if ($length !== null && trim($length) !== '') {
            $type .= '(' . trim($length) . ')';
        }
    }

    /*
     * DECIMAL.
     */
    elseif (
        in_array($type, ['DECIMAL', 'NUMERIC'], true)
    ) {
        if (
            $precision !== null &&
            trim($precision) !== ''
        ) {
            $type .= '(' . trim($precision);

            if (
                $scale !== null &&
                trim($scale) !== ''
            ) {
                $type .= ',' . trim($scale);
            }

            $type .= ')';
        }
    }

    /*
     * UNSIGNED.
     */
    foreach ($flags as $flag) {
        if (strtoupper(trim($flag)) === 'UNSIGNED') {
            $type .= ' UNSIGNED';
            break;
        }
    }

    return $type;
}


/**
 * Normaliza parâmetros explícitos do MWB.
 */
function normalizeMwbExplicitParams(
    string $params,
    string $type
): string {
    $params = trim($params);

    /*
     * ENUM/SET normalmente chegam como:
     *
     * ('F', 'J')
     */
    if (in_array($type, ['ENUM', 'SET'], true)) {
        $params = trim(
            $params,
            " \t\r\n()\""
        );

        $params = preg_replace(
            '/\s*,\s*/',
            ',',
            $params
        ) ?? $params;

        return $params;
    }

    return preg_replace(
        '/\s*,\s*/',
        ',',
        $params
    ) ?? $params;
}


/*
|--------------------------------------------------------------------------
| NORMALIZAÇÃO
|--------------------------------------------------------------------------
*/

/**
 * Normaliza tipos SQL para permitir comparação sem diferenças
 * irrelevantes de sintaxe.
 */
function normalizeSqlType(string $type): string
{
    $type = strtoupper(trim($type));

    $type = preg_replace(
        '/\s+/',
        ' ',
        $type
    ) ?? $type;

    $type = preg_replace(
        '/\s*\(\s*/',
        '(',
        $type
    ) ?? $type;

    $type = preg_replace(
        '/\s*\)/',
        ')',
        $type
    ) ?? $type;

    $type = preg_replace(
        '/\s*,\s*/',
        ',',
        $type
    ) ?? $type;

    $type = preg_replace(
        '/\bINTEGER\b/',
        'INT',
        $type
    ) ?? $type;

    $type = preg_replace(
        '/\bNUMERIC\b/',
        'DECIMAL',
        $type
    ) ?? $type;

    $type = preg_replace(
        '/\bDOUBLE\s+PRECISION\b/',
        'DOUBLE',
        $type
    ) ?? $type;

    /*
     * O MySQL/MariaDB aceita display width em inteiros,
     * como INT(11) e BIGINT(20). Isso não representa
     * diferença real de capacidade do tipo.
     */
    if (preg_match(
        '/^(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)(?:\([^)]*\))?(\s+UNSIGNED)?$/',
        $type,
        $m
    )) {
        $type = $m[1] . ($m[2] ?? '');
    }

    /*
     * ENUM / SET.
     */
    if (preg_match(
        '/^(ENUM|SET)\((.*)\)$/i',
        $type,
        $m
    )) {
        $values = preg_replace(
            '/\s*,\s*/',
            ',',
            trim($m[2])
        ) ?? trim($m[2]);

        $type = strtoupper($m[1]) . '(' . $values . ')';
    }

    return $type;
}


/**
 * Normaliza DEFAULT.
 */
function normalizeDefault(?string $v): ?string
{
    if ($v === null) {
        return null;
    }

    $v = trim($v);

    if ($v === '') {
        return null;
    }

    /*
     * Remove aspas externas.
     */
    if (
        strlen($v) >= 2 &&
        (
            (
                $v[0] === "'" &&
                $v[strlen($v) - 1] === "'"
            ) ||
            (
                $v[0] === '"' &&
                $v[strlen($v) - 1] === '"'
            )
        )
    ) {
        $v = substr($v, 1, -1);
    }

    $u = strtoupper($v);

    if ($u === 'NULL') {
        return 'NULL';
    }

    if (
        $u === 'CURRENT_TIMESTAMP()' ||
        $u === 'CURRENT_TIMESTAMP'
    ) {
        return 'CURRENT_TIMESTAMP';
    }

    /*
     * Normaliza números:
     *
     * 0.00 -> 0
     * 10.00 -> 10
     */
    if (preg_match(
        '/^[+-]?(?:\d+\.?\d*|\.\d+)$/',
        $v
    )) {
        $normalized = rtrim(
            rtrim($v, '0'),
            '.'
        );

        if (
            $normalized === '' ||
            $normalized === '-0' ||
            $normalized === '+0'
        ) {
            return '0';
        }

        return $normalized;
    }

    return $v;
}


/**
 * Compara DEFAULT considerando que, em coluna nullable,
 * ausência de DEFAULT e DEFAULT NULL são equivalentes.
 */
function defaultsEqualForColumn(
    ?string $database,
    ?string $model,
    bool $databaseNullable,
    bool $modelNullable
): bool {
    $a = normalizeDefault($database);
    $b = normalizeDefault($model);

    if (defaultsEqual($a, $b)) {
        return true;
    }

    if (
        (
            $a === null &&
            $b === 'NULL' &&
            $databaseNullable
        ) ||
        (
            $b === null &&
            $a === 'NULL' &&
            $modelNullable
        )
    ) {
        return true;
    }

    return false;
}


function defaultsEqual(
    ?string $a,
    ?string $b
): bool {
    return normalizeDefault($a) === normalizeDefault($b);
}


/*
|--------------------------------------------------------------------------
| COLUNAS IGNORADAS
|--------------------------------------------------------------------------
*/

function isIgnoredColumn(string $column): bool
{
    return in_array(
        strtolower(trim($column)),
        [
            'created_at',
            'updated_at',
            'created_by',
            'updated_by',
        ],
        true
    );
}


/*
|--------------------------------------------------------------------------
| COMPARAÇÃO
|--------------------------------------------------------------------------
*/

function compareSchemas(
    array $database,
    array $model
): array {
    $differences = [];

    /*
     * Tabelas.
     */
    $allTables = array_unique(
        array_merge(
            array_keys($database),
            array_keys($model)
        )
    );

    natcasesort($allTables);

    $tables = [];

    foreach ($allTables as $table) {
        $dbTable = findCaseInsensitive(
            $database,
            $table
        );

        $modelTable = findCaseInsensitive(
            $model,
            $table
        );

        if ($dbTable === null) {
            $differences[] = [
                'type' => 'table_added',
                'table' => $table,
            ];

            $tables[$table] = [
                'status' => 'missing_database',
            ];

            continue;
        }

        if ($modelTable === null) {
            $differences[] = [
                'type' => 'table_added_model',
                'table' => $table,
            ];

            $tables[$table] = [
                'status' => 'missing_model',
            ];

            continue;
        }

        $tableDifferences = compareTable(
            $dbTable,
            $modelTable
        );

        $tables[$table] = [
            'status' => empty($tableDifferences)
                ? 'ok'
                : 'different',
            'differences' => $tableDifferences,
        ];

        foreach ($tableDifferences as $difference) {
            $differences[] = $difference;
        }
    }

    return [
        'tables' => $tables,
        'differences' => $differences,
    ];
}


/**
 * Compara uma tabela.
 */
function compareTable(
    array $database,
    array $model
): array {
    $differences = [];

    $databaseColumns = $database['columns'];
    $modelColumns = $model['columns'];

    /*
     * Remove colunas de auditoria da comparação.
     */
    $databaseColumns = array_filter(
        $databaseColumns,
        fn ($column, $name) =>
            !isIgnoredColumn($name),
        ARRAY_FILTER_USE_BOTH
    );

    $modelColumns = array_filter(
        $modelColumns,
        fn ($column, $name) =>
            !isIgnoredColumn($name),
        ARRAY_FILTER_USE_BOTH
    );

    $allColumns = array_unique(
        array_merge(
            array_keys($databaseColumns),
            array_keys($modelColumns)
        )
    );

    natcasesort($allColumns);

    foreach ($allColumns as $columnName) {
        $dbColumn = findCaseInsensitive(
            $databaseColumns,
            $columnName
        );

        $modelColumn = findCaseInsensitive(
            $modelColumns,
            $columnName
        );

        /*
         * Existe apenas no modelo.
         */
        if ($dbColumn === null) {
            $differences[] = [
                'type' => 'column_model_only',
                'table' => $database['name'],
                'column' => $columnName,
            ];

            continue;
        }

        /*
         * Existe apenas no banco.
         */
        if ($modelColumn === null) {
            $databaseColumnNames = array_keys($databaseColumns);
            $databasePosition = array_search($columnName, $databaseColumnNames, true);

            if ($databasePosition === false) {
                foreach ($databaseColumnNames as $position => $databaseColumnName) {
                    if (strcasecmp($databaseColumnName, $columnName) === 0) {
                        $databasePosition = $position;
                        $columnName = $databaseColumnName;
                        break;
                    }
                }
            }

            $previousColumn = null;
            if ($databasePosition !== false && $databasePosition > 0) {
                $previousColumn = $databaseColumnNames[$databasePosition - 1];
            }

            $databaseOnlyColumn = $dbColumn;

            $differences[] = [
                'type' => 'column_database_only',
                'table' => $database['name'],
                'column' => $columnName,
                'previous' => $previousColumn,
                'databaseType' => normalizeSqlType($databaseOnlyColumn['type']),
                'databaseNullable' => $databaseOnlyColumn['nullable'] ? 'NULL' : 'NOT NULL',
                'databaseDefault' => $databaseOnlyColumn['default'],
            ];

            continue;
        }

        /*
         * Tipo.
         */
        if (
            normalizeSqlType($dbColumn['type']) !==
            normalizeSqlType($modelColumn['type'])
        ) {
            $differences[] = [
                'type' => 'column_type',
                'table' => $database['name'],
                'column' => $columnName,
                'model' => normalizeSqlType(
                    $modelColumn['type']
                ),
                'database' => normalizeSqlType(
                    $dbColumn['type']
                ),
            ];
        }

        /*
         * Nulabilidade.
         */
        if (
            (bool) $dbColumn['nullable'] !==
            (bool) $modelColumn['nullable']
        ) {
            $differences[] = [
                'type' => 'column_nullable',
                'table' => $database['name'],
                'column' => $columnName,
                'model' => $modelColumn['nullable']
                    ? 'NULL'
                    : 'NOT NULL',
                'database' => $dbColumn['nullable']
                    ? 'NULL'
                    : 'NOT NULL',
            ];
        }

        /*
         * DEFAULT.
         */
        if (
            !defaultsEqualForColumn(
                $dbColumn['default'],
                $modelColumn['default'],
                (bool) $dbColumn['nullable'],
                (bool) $modelColumn['nullable']
            )
        ) {
            $differences[] = [
                'type' => 'column_default',
                'table' => $database['name'],
                'column' => $columnName,
                'model' => formatValue(
                    $modelColumn['default']
                ),
                'database' => formatValue(
                    $dbColumn['default']
                ),
            ];
        }
    }

    /*
     * Posição dos campos.
     *
     * A comparação considera apenas os campos que existem nos dois lados
     * e ignora as colunas de auditoria. Assim, a ausência de um campo não
     * provoca uma cascata de diferenças de posição nos campos seguintes.
     */
    $databaseOrderedColumns = array_keys($databaseColumns);
    $modelOrderedColumns = array_keys($modelColumns);

    $databaseCommonOrder = [];
    foreach ($databaseOrderedColumns as $column) {
        if (findCaseInsensitive($modelColumns, $column) !== null) {
            $databaseCommonOrder[] = strtolower($column);
        }
    }

    $modelCommonOrder = [];
    foreach ($modelOrderedColumns as $column) {
        if (findCaseInsensitive($databaseColumns, $column) !== null) {
            $modelCommonOrder[] = strtolower($column);
        }
    }

    $modelPositions = [];
    foreach ($modelCommonOrder as $position => $column) {
        $modelPositions[$column] = $position + 1;
    }

    $databasePositions = [];
    foreach ($databaseCommonOrder as $position => $column) {
        $databasePositions[$column] = $position + 1;
    }

    foreach ($databaseCommonOrder as $column) {
        if (
            isset($modelPositions[$column], $databasePositions[$column]) &&
            $modelPositions[$column] !== $databasePositions[$column]
        ) {
            $displayName = $column;

            foreach ($databaseOrderedColumns as $databaseColumnName) {
                if (strcasecmp($databaseColumnName, $column) === 0) {
                    $displayName = $databaseColumnName;
                    break;
                }
            }

            $differences[] = [
                'type' => 'column_position',
                'table' => $database['name'],
                'column' => $displayName,
                'modelPosition' => $modelPositions[$column],
                'databasePosition' => $databasePositions[$column],
            ];
        }
    }

    /*
     * PRIMARY KEY.
     */
    $databasePrimary = array_map(
        'strtolower',
        $database['primaryKey'] ?? []
    );

    $modelPrimary = array_map(
        'strtolower',
        $model['primaryKey'] ?? []
    );

    if ($databasePrimary !== $modelPrimary) {
        $differences[] = [
            'type' => 'primary_key',
            'table' => $database['name'],
            'model' => implode(
                ', ',
                $model['primaryKey'] ?? []
            ),
            'database' => implode(
                ', ',
                $database['primaryKey'] ?? []
            ),
        ];
    }

    return $differences;
}


/*
|--------------------------------------------------------------------------
| AUXILIARES
|--------------------------------------------------------------------------
*/

function findCaseInsensitive(
    array $array,
    string $name
): ?array {
    foreach ($array as $key => $value) {
        if (strcasecmp($key, $name) === 0) {
            return $value;
        }
    }

    return null;
}


/**
 * Obtém um value direto de um objeto.
 */
function getDirectValue(
    DOMXPath $xpath,
    DOMElement $node,
    string $key
): ?string {
    $valueNode = getDirectValueNode(
        $xpath,
        $node,
        $key
    );

    if ($valueNode === null) {
        return null;
    }

    /*
     * Se for um value simples, retorna o texto.
     */
    return trim($valueNode->textContent);
}


/**
 * Obtém o nó value diretamente associado à chave.
 */
function getDirectValueNode(
    DOMXPath $xpath,
    DOMElement $node,
    string $key
): ?DOMElement {
    foreach (
        $xpath->query(
            './value[@key="' .
            htmlspecialchars($key, ENT_QUOTES) .
            '"]',
            $node
        ) as $valueNode
    ) {
        return $valueNode;
    }

    return null;
}


/**
 * Obtém uma lista de strings de um value.
 */
function getDirectValueList(
    DOMXPath $xpath,
    DOMElement $node,
    string $key
): array {
    $container = getDirectValueNode(
        $xpath,
        $node,
        $key
    );

    if ($container === null) {
        return [];
    }

    $values = [];

    foreach (
        $xpath->query(
            './value[@type="string"]',
            $container
        ) as $value
    ) {
        $values[] = trim($value->textContent);
    }

    return $values;
}


/**
 * Formata valores para o relatório.
 */
function formatValue(?string $value): string
{
    if ($value === null || $value === '') {
        return '(nenhum)';
    }

    return $value;
}


/*
|--------------------------------------------------------------------------
| RELATÓRIO TXT
|--------------------------------------------------------------------------
*/

function renderTextReport(array $report): string
{
    $lines = [];

    $lines[] = '========================================================================';
    $lines[] = 'COMPARAÇÃO DA MODELAGEM';
    $lines[] = '========================================================================';
    $lines[] = '';
    $lines[] = 'TABELAS';
    $lines[] = '------------------------------------------------------------------------';

    foreach ($report['tables'] as $tableName => $table) {
        if ($table['status'] === 'ok') {
            $lines[] = "✓ {$tableName}";
            continue;
        }

        if ($table['status'] === 'missing_database') {
            $lines[] = "✗ {$tableName}";
            $lines[] =
                "    + existe no MODELO, mas não no BANCO";
            continue;
        }

        if ($table['status'] === 'missing_model') {
            $lines[] = "✗ {$tableName}";
            $lines[] =
                "    - existe no BANCO, mas não no MODELO";
            continue;
        }

        $lines[] = "✗ {$tableName}";

        foreach ($table['differences'] as $difference) {
            switch ($difference['type']) {
                case 'column_model_only':
                    $lines[] =
                        "    + {$difference['column']} — " .
                        "existe no MODELO, mas não no BANCO";
                    break;

                case 'column_database_only':
                    $lines[] =
                        "    - {$difference['column']} — " .
                        "existe no BANCO, mas não no MODELO";

                    if ($difference['previous'] !== null) {
                        $lines[] =
                            "        Depois de : " .
                            $difference['previous'];
                    } else {
                        $lines[] =
                            "        Depois de : (primeiro campo da tabela)";
                    }

                    $lines[] =
                        "        Tipo      : " .
                        $difference['databaseType'];

                    $lines[] =
                        "        Nulabilidade: " .
                        $difference['databaseNullable'];

                    $lines[] =
                        "        Default    : " .
                        formatValue($difference['databaseDefault']);
                    break;

                case 'column_position':
                    $lines[] =
                        "    ✗ {$difference['column']}.posição";

                    $lines[] =
                        "        Modelo : posição {$difference['modelPosition']}";

                    $lines[] =
                        "        Banco  : posição {$difference['databasePosition']}";

                    $lines[] =
                        "        Corrigir a posição do campo na modelagem.";
                    break;

                case 'column_type':
                    $lines[] =
                        "    ✗ {$difference['column']}.tipo";

                    $lines[] =
                        "        Modelo : " .
                        $difference['model'];

                    $lines[] =
                        "        Banco  : " .
                        $difference['database'];
                    break;

                case 'column_nullable':
                    $lines[] =
                        "    ✗ {$difference['column']}.nulabilidade";

                    $lines[] =
                        "        Modelo : " .
                        $difference['model'];

                    $lines[] =
                        "        Banco  : " .
                        $difference['database'];
                    break;

                case 'column_default':
                    $lines[] =
                        "    ✗ {$difference['column']}.default";

                    $lines[] =
                        "        Modelo : " .
                        $difference['model'];

                    $lines[] =
                        "        Banco  : " .
                        $difference['database'];
                    break;

                case 'primary_key':
                    $lines[] =
                        "    ✗ PRIMARY KEY";

                    $lines[] =
                        "        Modelo : " .
                        formatValue($difference['model']);

                    $lines[] =
                        "        Banco  : " .
                        formatValue($difference['database']);
                    break;
            }
        }
    }

    $lines[] = '';
    $lines[] = '========================================================================';
    $lines[] = 'RESUMO';
    $lines[] = '========================================================================';
    $lines[] =
        'Diferenças encontradas: ' .
        count($report['differences']);

    return implode(PHP_EOL, $lines);
}
