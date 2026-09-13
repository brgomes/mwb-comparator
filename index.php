<?php
declare(strict_types=1);

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
 *
 * Uso:
 *   php index.php database.sql model.mwb
 *
 * O relatório TXT é gerado automaticamente como relatorio.txt.
 *   php index.php database.sql model.mwb relatorio.txt
 */

if (PHP_SAPI !== 'cli') {
    exit("Execute este arquivo pela CLI.\n");
}

if ($argc < 3 || $argc > 4) {
    exit("Uso: php index.php database.sql model.mwb [relatorio.txt]\n");
}

$sqlFile = $argv[1];
$mwbFile = $argv[2];
$reportFile = $argv[3] ?? 'relatorio.txt';

// O relatório é sempre salvo na raiz do projeto, junto ao index.php.
$reportFile = basename($reportFile);
$reportPath = __DIR__ . DIRECTORY_SEPARATOR . $reportFile;

if (!is_file($sqlFile)) {
    fail("Arquivo SQL não encontrado: {$sqlFile}");
}
if (!is_file($mwbFile)) {
    fail("Arquivo MWB não encontrado: {$mwbFile}");
}

$db = parseSqlDump($sqlFile);
$model = parseMwb($mwbFile);
$report = compareSchemas($db, $model);

echo renderTextReport($report);

if ($reportFile !== null) {
    $target = $reportPath;
    $ok = @file_put_contents($target, renderTextReport($report) . PHP_EOL);
    if ($ok === false) {
        fail("não foi possível criar {$target} (permissão de escrita).");
    }

    echo "\nRelatório TXT salvo na raiz do projeto: {$reportFile}\n";
}

// Diferenças são o resultado da comparação, não um erro de execução.
exit(0);

/* ============================================================
 * SQL
 * ============================================================ */

function parseSqlDump(string $file): array
{
    $sql = file_get_contents($file);
    if ($sql === false) fail("Não foi possível ler o SQL.");

    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    $schema = [];

    $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`[^`]+`\.)?`?([^`\s(]+)`?\s*\((.*?)\)\s*(?:ENGINE\b|;)/is';

    if (!preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
        fail("Nenhum CREATE TABLE foi encontrado no SQL.");
    }

    foreach ($matches as $match) {
        $table = normalizeIdentifier($match[1]);
        $schema[$table] = parseCreateTableBody($match[2]);
    }

    return $schema;
}

function parseCreateTableBody(string $body): array
{
    $columns = [];
    $primary = [];

    foreach (splitSqlDefinitions($body) as $definition) {
        $definition = trim($definition);
        if ($definition === '') continue;

        if (preg_match('/^\s*PRIMARY\s+KEY\s*\((.*?)\)/is', $definition, $m)) {
            $primary = parseIdentifierList($m[1]);
            continue;
        }

        if (preg_match('/^\s*(?:CONSTRAINT|FOREIGN\s+KEY|KEY|INDEX|UNIQUE|FULLTEXT|SPATIAL|CHECK)\b/i', $definition)) {
            continue;
        }

        if (!preg_match('/^\s*`([^`]+)`\s+(.+)$/is', $definition, $m)) {
            continue;
        }

        $name = normalizeIdentifier($m[1]);
        $rest = trim($m[2]);

        $columns[$name] = [
            'name' => $name,
            'type' => normalizeSqlType(extractSqlType($rest)),
            'nullable' => !preg_match('/\bNOT\s+NULL\b/i', $rest),
            'default' => normalizeDefault(extractSqlDefault($rest)),
        ];
    }

    // PRIMARY KEY inline: `id` BIGINT ... PRIMARY KEY
    foreach (splitSqlDefinitions($body) as $definition) {
        if (preg_match('/^\s*`([^`]+)`\s+(.+)$/is', trim($definition), $m) &&
            preg_match('/\bPRIMARY\s+KEY\b/i', $m[2])) {
            $primary[] = normalizeIdentifier($m[1]);
        }
    }

    return [
        'columns' => $columns,
        'primary' => array_values(array_unique($primary)),
    ];
}

function extractSqlType(string $rest): string
{
    if (preg_match('/^([a-zA-Z]+)(?:\s*\(([^)]*)\))?((?:\s+UNSIGNED)?(?:\s+ZEROFILL)?)/i', $rest, $m)) {
        $base = strtoupper($m[1]);
        $params = isset($m[2]) ? trim($m[2]) : '';
        $attrs = strtoupper(trim($m[3] ?? ''));

        return $base . ($params !== '' ? '(' . $params . ')' : '') . $attrs;
    }
    return '';
}

function extractSqlDefault(string $rest): ?string
{
    if (!preg_match('/\bDEFAULT\s+/i', $rest, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $start = $m[0][1] + strlen($m[0][0]);
    $tail = ltrim(substr($rest, $start));
    if ($tail === '') return null;

    if ($tail[0] === "'" || $tail[0] === '"') {
        $quote = $tail[0];
        $value = '';
        $escaped = false;

        for ($i = 1, $len = strlen($tail); $i < $len; $i++) {
            $c = $tail[$i];
            if ($escaped) {
                $value .= $c;
                $escaped = false;
            } elseif ($c === '\\') {
                $escaped = true;
                $value .= $c;
            } elseif ($c === $quote) {
                return $value;
            } else {
                $value .= $c;
            }
        }
        return $value;
    }

    if (preg_match('/^([^\s,]+)/', $tail, $m)) {
        return $m[1];
    }

    return null;
}

function splitSqlDefinitions(string $body): array
{
    $parts = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $escaped = false;

    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
        $c = $body[$i];

        if ($quote !== null) {
            $current .= $c;
            if ($escaped) {
                $escaped = false;
            } elseif ($c === '\\') {
                $escaped = true;
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($c === "'" || $c === '"') {
            $quote = $c;
            $current .= $c;
        } elseif ($c === '(') {
            $depth++;
            $current .= $c;
        } elseif ($c === ')') {
            $depth--;
            $current .= $c;
        } elseif ($c === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
        } else {
            $current .= $c;
        }
    }

    if (trim($current) !== '') $parts[] = trim($current);
    return $parts;
}

/* ============================================================
 * MWB
 * ============================================================ */

function parseMwb(string $file): array
{
    if (!class_exists('ZipArchive')) {
        fail("A extensão ZipArchive não está habilitada.");
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        fail("Não foi possível abrir o MWB.");
    }

    $xml = $zip->getFromName('document.mwb.xml');
    $zip->close();

    if ($xml === false) {
        fail("document.mwb.xml não foi encontrado dentro do MWB.");
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;

    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        fail("O XML interno do MWB não pôde ser interpretado.");
    }

    $xpath = new DOMXPath($dom);
    $schema = [];

    // O seu MWB possui exatamente objetos db.mysql.Table.
    $tables = $xpath->query('//*[@struct-name="db.mysql.Table"]');
    if ($tables === false) fail("Não foi possível localizar as tabelas no MWB.");

    foreach ($tables as $tableNode) {
        if (!$tableNode instanceof DOMElement) continue;

        $tableName = directValue($tableNode, 'name');
        if ($tableName === null || trim($tableName) === '') {
            // O MWB contém objetos Table vazios; eles não representam tabelas.
            continue;
        }

        $tableName = normalizeIdentifier($tableName);
        $columns = [];

        $columnList = directValueNode($tableNode, 'columns');
        if ($columnList !== null) {
            foreach ($columnList->childNodes as $columnNode) {
                if (!$columnNode instanceof DOMElement ||
                    $columnNode->getAttribute('struct-name') !== 'db.mysql.Column') {
                    continue;
                }

                $name = directValue($columnNode, 'name');
                if ($name === null || trim($name) === '') continue;

                $name = normalizeIdentifier($name);

                $type = buildMwbType($columnNode);
                $isNotNull = directValue($columnNode, 'isNotNull');
                $default = directValue($columnNode, 'defaultValue');

                $defaultIsNull = directValue($columnNode, 'defaultValueIsNull');
                if ($defaultIsNull === '1') {
                    $default = 'NULL';
                }

                $columns[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'nullable' => !in_array((string)$isNotNull, ['1', 'true'], true),
                    'default' => normalizeDefault($default),
                ];
            }
        }

        $primary = [];
        $indexList = directValueNode($tableNode, 'indices');

        if ($indexList !== null) {
            foreach ($indexList->childNodes as $indexNode) {
                if (!$indexNode instanceof DOMElement ||
                    $indexNode->getAttribute('struct-name') !== 'db.mysql.Index') {
                    continue;
                }

                $isPrimary = directValue($indexNode, 'isPrimary');
                $indexType = strtoupper((string)directValue($indexNode, 'indexType'));

                if ($isPrimary !== '1' && $indexType !== 'PRIMARY') continue;

                $indexColumns = directValueNode($indexNode, 'columns');
                if ($indexColumns === null) continue;

                foreach ($indexColumns->childNodes as $indexColumnNode) {
                    if (!$indexColumnNode instanceof DOMElement) continue;

                    $referenced = directLink($indexColumnNode, 'referencedColumn');
                    if ($referenced !== null) {
                        // Resolve pelo ID depois.
                        $primary[] = ['id' => $referenced];
                    }
                }
            }
        }

        // Resolve os IDs das colunas da PK.
        $idToName = [];
        if ($columnList !== null) {
            foreach ($columnList->childNodes as $columnNode) {
                if ($columnNode instanceof DOMElement) {
                    $n = directValue($columnNode, 'name');
                    if ($n !== null && $columnNode->hasAttribute('id')) {
                        $idToName[$columnNode->getAttribute('id')] = normalizeIdentifier($n);
                    }
                }
            }
        }

        $resolvedPrimary = [];
        foreach ($primary as $pk) {
            if (isset($idToName[$pk['id']])) {
                $resolvedPrimary[] = $idToName[$pk['id']];
            }
        }

        $schema[$tableName] = [
            'columns' => $columns,
            'primary' => array_values(array_unique($resolvedPrimary)),
        ];
    }

    if (!$schema) fail("Nenhuma tabela foi extraída do MWB.");

    return $schema;
}

function directValueNode(DOMElement $node, string $key): ?DOMElement
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement &&
            $child->tagName === 'value' &&
            $child->getAttribute('key') === $key) {
            return $child;
        }
    }
    return null;
}

function directValue(DOMElement $node, string $key): ?string
{
    $v = directValueNode($node, $key);
    if ($v === null) return null;
    return trim($v->textContent);
}

function directLink(DOMElement $node, string $key): ?string
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement &&
            $child->tagName === 'link' &&
            $child->getAttribute('key') === $key) {
            return trim($child->textContent);
        }
    }
    return null;
}

function buildMwbType(DOMElement $column): string
{
    /*
     * No MWB o simpleType é armazenado como, por exemplo:
     *   com.mysql.rdbms.mysql.datatype.bigint
     *   com.mysql.rdbms.mysql.datatype.varchar
     *   com.mysql.rdbms.mysql.datatype.timestamp_f
     *
     * Não podemos usar basename(), pois o separador é ponto, não barra.
     */
    $link = directLink($column, 'simpleType');
    $userType = directLink($column, 'userType');
    $base = '';
    $forceBooleanWidth = false;

    // O Workbench usa User Type para campos booleanos.
    if ($userType !== null) {
        $userTypeBase = strtolower(trim((string)$userType));

        if (str_ends_with($userTypeBase, '.boolean')) {
            $base = 'tinyint';
            $forceBooleanWidth = true;
        } elseif (str_ends_with($userTypeBase, '.integer')) {
            // Alguns campos do Workbench usam UserDatatype em vez de
            // SimpleDatatype. O UserDatatype "integer" corresponde a INT.
            $base = 'int';
        }
    }

    if ($base === '' && $link !== null && $link !== '') {
        $parts = explode('.', strtolower(trim($link)));
        $base = end($parts) ?: '';
    }

    $baseMap = [
        'bigint' => 'BIGINT',
        'int' => 'INT',
        'integer' => 'INT',
        'mediumint' => 'MEDIUMINT',
        'smallint' => 'SMALLINT',
        'tinyint' => 'TINYINT',
        'decimal' => 'DECIMAL',
        'numeric' => 'DECIMAL',
        'float' => 'FLOAT',
        'double' => 'DOUBLE',
        'double_precision' => 'DOUBLE',
        'real' => 'REAL',
        'varchar' => 'VARCHAR',
        'char' => 'CHAR',
        'binary' => 'BINARY',
        'varbinary' => 'VARBINARY',
        'text' => 'TEXT',
        'tinytext' => 'TINYTEXT',
        'mediumtext' => 'MEDIUMTEXT',
        'longtext' => 'LONGTEXT',
        'blob' => 'BLOB',
        'tinyblob' => 'TINYBLOB',
        'mediumblob' => 'MEDIUMBLOB',
        'longblob' => 'LONGBLOB',
        'date' => 'DATE',
        'datetime' => 'DATETIME',
        'datetime_f' => 'DATETIME',
        'timestamp' => 'TIMESTAMP',
        'timestamp_f' => 'TIMESTAMP',
        'time' => 'TIME',
        'year' => 'YEAR',
        'enum' => 'ENUM',
        'set' => 'SET',
        'json' => 'JSON',
        'boolean' => 'TINYINT',
        'bool' => 'TINYINT',
        'integer' => 'INT',
    ];

    $type = $baseMap[$base] ?? strtoupper($base);

    $explicit = directValue($column, 'datatypeExplicitParams');
    $length = directValue($column, 'length');
    $precision = directValue($column, 'precision');
    $scale = directValue($column, 'scale');

    $params = '';

    if ($forceBooleanWidth) {
        $params = '1';
    } elseif ($explicit !== null && trim($explicit) !== '') {
        $params = normalizeMwbExplicitParams($explicit, $type);
    } elseif (in_array($type, ['VARCHAR', 'CHAR', 'VARBINARY', 'BINARY'], true) &&
              $length !== null && (int)$length >= 0) {
        $params = (string)(int)$length;
    } elseif ($type === 'DECIMAL' &&
              $precision !== null && (int)$precision >= 0) {
        $params = (string)(int)$precision;
        if ($scale !== null && (int)$scale >= 0) {
            $params .= ',' . (string)(int)$scale;
        }
    } elseif (in_array($type, ['BIGINT', 'INT', 'MEDIUMINT', 'SMALLINT', 'TINYINT'], true) &&
              $precision !== null && (int)$precision >= 0) {
        // O Workbench preserva o display width do modelo/dump.
        $params = (string)(int)$precision;
    }

    if ($params !== '' && !in_array($type, [
        'TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT',
        'BLOB', 'TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB',
        'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR', 'JSON',
        'FLOAT', 'DOUBLE', 'REAL'
    ], true)) {
        $type .= '(' . $params . ')';
    }

    $flags = directValueNode($column, 'flags');
    $unsigned = false;

    if ($flags !== null) {
        foreach ($flags->childNodes as $flag) {
            if ($flag instanceof DOMElement &&
                strtoupper(trim($flag->textContent)) === 'UNSIGNED') {
                $unsigned = true;
                break;
            }
        }
    }

    if ($unsigned && !preg_match('/\bUNSIGNED\b/i', $type)) {
        $type .= ' UNSIGNED';
    }

    return normalizeSqlType($type);
}

function normalizeMwbExplicitParams(string $params, string $type): string
{
    $params = trim($params);

    // ENUM/SET no MWB costuma vir como "('F', 'J')".
    if (in_array($type, ['ENUM', 'SET'], true)) {
        $params = trim($params, " \t\r\n()\"");
        $params = preg_replace('/\s*,\s*/', ',', $params) ?? $params;
        return $params;
    }

    return preg_replace('/\s*,\s*/', ',', $params) ?? $params;
}

/* ============================================================
 * COMPARAÇÃO
 * ============================================================ */

function isIgnoredColumn(string $column): bool
{
    return in_array(
        strtolower(trim($column)),
        ['created_at', 'updated_at', 'created_by', 'updated_by'],
        true
    );
}

function compareSchemas(array $database, array $model): array
{
    $tables = array_values(array_unique(array_merge(array_keys($database), array_keys($model))));
    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

    $r = [
        'tables_only_database' => [],
        'tables_only_model' => [],
        'tables_equal' => [],
        'tables' => [],
        'differences' => 0,
    ];

    foreach ($tables as $table) {
        $inDb = isset($database[$table]);
        $inModel = isset($model[$table]);

        if (!$inDb) {
            $r['tables_only_model'][] = $table;
            $r['differences']++;
        } elseif (!$inModel) {
            $r['tables_only_database'][] = $table;
            $r['differences']++;
        } else {
            $diff = compareTable($database[$table], $model[$table]);

            if ($diff['different']) {
                $r['tables'][$table] = $diff;
                $r['differences'] += $diff['difference_count'];
            } else {
                $r['tables_equal'][] = $table;
            }
        }
    }

    return $r;
}

function compareTable(array $db, array $model): array
{
    $r = [
        'different' => false,
        'difference_count' => 0,
        'columns_only_database' => [],
        'columns_only_model' => [],
        'columns' => [],
        'primary_database' => $db['primary'],
        'primary_model' => $model['primary'],
        'primary_different' => false,
    ];

    $columns = array_values(array_unique(array_merge(
        array_keys($db['columns']),
        array_keys($model['columns'])
    )));

    // Estes campos existem propositalmente apenas no banco de dados
    // e não fazem parte da modelagem do Workbench.
    $columns = array_values(array_filter(
        $columns,
        fn (string $column): bool => !isIgnoredColumn($column)
    ));

    sort($columns, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($columns as $column) {
        $inDb = isset($db['columns'][$column]);
        $inModel = isset($model['columns'][$column]);

        if (!$inDb) {
            $r['columns_only_model'][] = $column;
            $r['difference_count']++;
            continue;
        }

        if (!$inModel) {
            $r['columns_only_database'][] = $column;
            $r['difference_count']++;
            continue;
        }

        $changes = [];

        foreach (['type', 'nullable', 'default'] as $property) {
            $a = $db['columns'][$column][$property];
            $b = $model['columns'][$column][$property];

            $equal = match ($property) {
                'type' => normalizeSqlType((string)$a) === normalizeSqlType((string)$b),
                'nullable' => $a === $b,
                'default' => defaultsEqualForColumn($a, $b, (bool)$db['columns'][$column]['nullable'], (bool)$model['columns'][$column]['nullable']),
            };

            if (!$equal) {
                $changes[$property] = [
                    'database' => formatComparable($a),
                    'model' => formatComparable($b),
                ];
            }
        }

        if ($changes) {
            $r['columns'][$column] = $changes;
            $r['difference_count'] += count($changes);
        }
    }

    $dbPk = array_map('normalizeIdentifier', $db['primary']);
    $modelPk = array_map('normalizeIdentifier', $model['primary']);

    if ($dbPk !== $modelPk) {
        $r['primary_different'] = true;
        $r['difference_count']++;
    }

    $r['different'] = $r['difference_count'] > 0;
    return $r;
}

/* ============================================================
 * NORMALIZAÇÃO
 * ============================================================ */

function normalizeIdentifier(string $v): string
{
    return strtolower(trim($v, " `\t\n\r\0\x0B"));
}

function normalizeSqlType(string $type): string
{
    $type = strtoupper(trim($type));
    $type = preg_replace('/\s+/', ' ', $type) ?? $type;
    $type = preg_replace('/\s*\(\s*/', '(', $type) ?? $type;
    $type = preg_replace('/\s*\)\s*/', ')', $type) ?? $type;
    $type = preg_replace('/\s*,\s*/', ',', $type) ?? $type;

    $type = preg_replace('/\bINTEGER\b/', 'INT', $type) ?? $type;
    $type = preg_replace('/\bNUMERIC\b/', 'DECIMAL', $type) ?? $type;
    $type = preg_replace('/\bDOUBLE\s+PRECISION\b/', 'DOUBLE', $type) ?? $type;

    // Display width (INT(11), BIGINT(20), TINYINT(1), etc.) não é
    // considerado uma diferença de tipo nesta comparação.
    if (preg_match('/^(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)(?:\([^)]*\))?(\s+UNSIGNED)?$/', $type, $m)) {
        $type = $m[1] . ($m[2] ?? '');
    }

    // Workbench e SQL podem representar os parâmetros de ENUM/SET com
    // espaços diferentes; para a comparação, a lista de valores é o que importa.
    if (preg_match('/^(ENUM|SET)\((.*)\)$/i', $type, $m)) {
        $values = preg_replace('/\s*,\s*/', ',', trim($m[2])) ?? trim($m[2]);
        $type = strtoupper($m[1]) . '(' . $values . ')';
    }

    return $type;
}

function normalizeDefault(?string $v): ?string
{
    if ($v === null) return null;

    $v = trim($v);
    if ($v === '') return null;

    // O parser SQL remove as aspas externas de strings; o Workbench pode
    // preservá-las. Para a comparação, normalizamos os dois formatos.
    if (strlen($v) >= 2 &&
        (($v[0] === "'" && $v[strlen($v) - 1] === "'") ||
         ($v[0] === '"' && $v[strlen($v) - 1] === '"'))) {
        $v = substr($v, 1, -1);
    }

    $u = strtoupper($v);

    if ($u === 'NULL') return 'NULL';
    if ($u === 'CURRENT_TIMESTAMP()' || $u === 'CURRENT_TIMESTAMP') return 'CURRENT_TIMESTAMP';

    // 0, 0.0 e 0.00 representam o mesmo default numérico.
    if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $v)) {
        $normalized = rtrim(rtrim($v, '0'), '.');
        if ($normalized === '' || $normalized === '-0' || $normalized === '+0') {
            return '0';
        }
        return $normalized;
    }

    return $v;
}

function defaultsEqual(?string $a, ?string $b): bool
{
    $a = normalizeDefault($a);
    $b = normalizeDefault($b);

    return $a === $b || strtoupper((string)$a) === strtoupper((string)$b);
}

function defaultsEqualForColumn(?string $database, ?string $model, bool $databaseNullable, bool $modelNullable): bool
{
    $a = normalizeDefault($database);
    $b = normalizeDefault($model);

    if (defaultsEqual($a, $b)) {
        return true;
    }

    // Em SQL, uma coluna nullable sem DEFAULT explícito tem NULL como
    // default efetivo. O Workbench frequentemente deixa defaultValue vazio
    // nesse caso. Não é uma diferença de esquema.
    if (($a === null && $b === 'NULL' && $databaseNullable) ||
        ($b === null && $a === 'NULL' && $modelNullable)) {
        return true;
    }

    return false;
}

function formatComparable(mixed $v): string
{
    if ($v === null) return '(nenhum)';
    if (is_bool($v)) return $v ? 'NULL' : 'NOT NULL';
    return (string)$v;
}

function parseIdentifierList(string $v): array
{
    $out = [];

    foreach (explode(',', $v) as $item) {
        $item = preg_replace('/\s+(?:ASC|DESC)\s*$/i', '', trim($item)) ?? trim($item);
        $item = trim($item, " `\t\n\r\0\x0B");
        if ($item !== '') $out[] = normalizeIdentifier($item);
    }

    return $out;
}

/* ============================================================
 * RELATÓRIO
 * ============================================================ */

function renderTextReport(array $r): string
{
    $out = [];
    $out[] = str_repeat('=', 72);
    $out[] = 'COMPARAÇÃO DA MODELAGEM';
    $out[] = str_repeat('=', 72);
    $out[] = '';

    $out[] = 'TABELAS';
    $out[] = str_repeat('-', 72);

    foreach ($r['tables_equal'] as $table) {
        $out[] = "✓ {$table}";
    }

    foreach ($r['tables_only_model'] as $table) {
        $out[] = "✗ {$table} — existe no MODELO, mas não no BANCO";
    }

    foreach ($r['tables_only_database'] as $table) {
        $out[] = "✗ {$table} — existe no BANCO, mas não no MODELO";
    }

    foreach ($r['tables'] as $table => $diff) {
        $out[] = "✗ {$table}";

        foreach ($diff['columns_only_model'] as $column) {
            $out[] = "    + {$column} — existe no MODELO, mas não no BANCO";
        }

        foreach ($diff['columns_only_database'] as $column) {
            $out[] = "    - {$column} — existe no BANCO, mas não no MODELO";
        }

        foreach ($diff['columns'] as $column => $changes) {
            foreach ($changes as $property => $values) {
                $label = match ($property) {
                    'type' => 'tipo',
                    'nullable' => 'nulabilidade',
                    'default' => 'default',
                    default => $property,
                };

                $out[] = "    ✗ {$column}.{$label}";
                $out[] = "        Modelo : {$values['model']}";
                $out[] = "        Banco  : {$values['database']}";
            }
        }

        if ($diff['primary_different']) {
            $out[] = "    ✗ PRIMARY KEY";
            $out[] = "        Modelo : (" . implode(', ', $diff['primary_model']) . ")";
            $out[] = "        Banco  : (" . implode(', ', $diff['primary_database']) . ")";
        }
    }

    $out[] = '';
    $out[] = str_repeat('=', 72);
    $out[] = 'RESUMO';
    $out[] = str_repeat('=', 72);
    $out[] = 'Diferenças encontradas: ' . $r['differences'];

    if ($r['differences'] === 0) {
        $out[] = '✓ Banco e modelo são equivalentes nos itens comparados.';
    }

    $out[] = '';
    return implode(PHP_EOL, $out);
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fail(string $message): never
{
    fwrite(STDERR, "ERRO: {$message}\n");
    exit(1);
}
