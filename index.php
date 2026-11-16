<?php

/**
 * Compara a estrutura de um banco MySQL/MariaDB ou PostgreSQL com um .mwb.
 * Uso: php index.php nome [config.php]
 * O PHP retorna um array de configurações indexadas pelo nome.
 */

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/database.php';

if (PHP_SAPI !== 'cli') {
    exit("Execute este arquivo pela CLI.\n");
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(main($argv));
    } catch (RuntimeException $exception) {
        fail($exception->getMessage());
    }
}

function main(array $arguments): int
{
    if (count($arguments) < 2 || count($arguments) > 3) {
        throw new RuntimeException('Uso: php index.php nome [config.php]. Exemplo: php index.php projeto_a');
    }

    $configFile = $arguments[2] ?? __DIR__ . '/config.php';
    $config = loadConfig($configFile, $arguments[1]);

    foreach (['zip', 'dom'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException("Instale/habilite a extensão PHP {$extension} para ler o arquivo MWB.");
        }
    }

    $model = parseMwb($config['model']);
    if ($model === []) {
        throw new RuntimeException('Nenhuma tabela MySQL encontrada no modelo MWB. Verifique o arquivo configurado.');
    }
    $database = readDatabaseSchema($config['database']);
    $output = renderTextReport(compareSchemas($database, $model, $config['ignore_tables']));

    if (@file_put_contents($config['report'], $output . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar o relatório TXT. Verifique report.path e as permissões de escrita.');
    }

    echo $output . PHP_EOL . PHP_EOL;
    echo "Relatório TXT salvo em: {$config['report']}" . PHP_EOL;

    // Diferenças são o resultado da comparação, não um erro de execução.
    return 0;
}


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
        $negative = str_starts_with($v, '-');
        $parts = explode('.', ltrim($v, '+-'), 2);
        $integer = ltrim($parts[0], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($parts[1] ?? '', '0');
        $normalized = $integer . ($fraction === '' ? '' : '.' . $fraction);
        if ($negative && $normalized !== '0') {
            $normalized = '-' . $normalized;
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
    array $model,
    array $ignoredTables = []
): array {
    $differences = [];
    $ignoredTables = array_fill_keys(array_map('strtolower', $ignoredTables), true);

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
        if (isset($ignoredTables[strtolower($table)])) {
            continue;
        }

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
