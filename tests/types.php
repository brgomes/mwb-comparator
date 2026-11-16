<?php

/** Valida leitura do ZIP/XML e comparação dos parâmetros de cada família. */
function checkTypeParameters(string $directory): void
{
    $cases = [
        ['TINYINT', ['precision' => '1'], 'TINYINT(1)', 'TINYINT(4)'],
        ['TINYINT', ['length' => '2'], 'TINYINT(2)', 'TINYINT(3)'],
        ['SMALLINT', ['precision' => '5'], 'SMALLINT(5)', 'SMALLINT(6)'],
        ['MEDIUMINT', ['precision' => '8'], 'MEDIUMINT(8)', 'MEDIUMINT(9)'],
        ['INT', ['precision' => '11'], 'INT(11)', 'INT(10)'],
        ['INTEGER', ['precision' => '11'], 'INT(11)', 'INT(10)'],
        ['BIGINT', ['precision' => '20'], 'BIGINT(20)', 'BIGINT(19)'],
        ['BIT', ['precision' => '8'], 'BIT(8)', 'BIT(1)'],
        ['BIT', ['length' => '16'], 'BIT(16)', 'BIT(8)'],
        ['CHAR', ['length' => '10'], 'CHAR(10)', 'CHAR(20)'],
        ['VARCHAR', ['length' => '80'], 'VARCHAR(80)', 'VARCHAR(40)'],
        ['BINARY', ['length' => '16'], 'BINARY(16)', 'BINARY(32)'],
        ['VARBINARY', ['length' => '80'], 'VARBINARY(80)', 'VARBINARY(40)'],
        ['DECIMAL', ['precision' => '12', 'scale' => '2'], 'DECIMAL(12,2)', 'DECIMAL(10,2)'],
        ['DECIMAL', ['precision' => '12', 'scale' => '0'], 'DECIMAL(12,0)', 'DECIMAL(12,2)'],
        ['FLOAT', ['precision' => '10', 'scale' => '3'], 'FLOAT(10,3)', 'FLOAT(10,2)'],
        ['DOUBLE', ['precision' => '16', 'scale' => '4'], 'DOUBLE(16,4)', 'DOUBLE(16,2)'],
        ['DOUBLE_PRECISION', ['precision' => '16', 'scale' => '4'], 'DOUBLE(16,4)', 'DOUBLE(16,3)'],
        ['TIME', ['length' => '3'], 'TIME(3)', 'TIME(6)'],
        ['DATETIME_F', ['length' => '6'], 'DATETIME(6)', 'DATETIME(3)'],
        ['TIMESTAMP_F', ['length' => '3'], 'TIMESTAMP(3)', 'TIMESTAMP(6)'],
        ['DATETIME', ['precision' => '4'], 'DATETIME(4)', 'DATETIME(2)'],
        ['TIME', ['length' => '0'], 'TIME(0)', 'TIME(3)'],
        ['YEAR', ['length' => '4'], 'YEAR(4)', 'YEAR(2)'],
        ['TINYINT', ['datatypeExplicitParams' => '(2)'], 'TINYINT(2)', 'TINYINT(4)'],
        ['FLOAT', ['datatypeExplicitParams' => '(10, 3)'], 'FLOAT(10,3)', 'FLOAT(10,2)'],
        ['TINYINT', [], 'TINYINT', 'TINYINT(1)'],
        ['DECIMAL', ['precision' => '12'], 'DECIMAL(12)', 'DECIMAL(10)'],
        ['TEXT', [], 'TEXT', 'MEDIUMTEXT'],
        ['BLOB', [], 'BLOB', 'LONGBLOB'],
        ['TINYINT', ['precision' => '1', 'flags' => ['UNSIGNED']], 'TINYINT(1) UNSIGNED', 'TINYINT(4) UNSIGNED'],
        ['INT', ['userType' => 'integer', 'precision' => '11'], 'INT(11)', 'INT(10)'],
        ['TINYINT', ['userType' => 'boolean'], 'TINYINT(1)', 'TINYINT(4)'],
    ];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $table = $dom->appendChild($dom->createElement('value'));
    $table->setAttribute('struct-name', 'db.mysql.Table');
    $value = static function (DOMElement $parent, string $key, string $text) use ($dom): DOMElement {
        $node = $parent->appendChild($dom->createElement('value'));
        $node->setAttribute('key', $key);
        $node->appendChild($dom->createTextNode($text));

        return $node;
    };
    $value($table, 'name', 'type_parameters');
    $columns = $value($table, 'columns', '');
    foreach ($cases as $index => [$type, $attributes]) {
        $column = $columns->appendChild($dom->createElement('value'));
        $column->setAttribute('type', 'object');
        $column->setAttribute('struct-name', 'db.mysql.Column');
        $value($column, 'name', 'field_' . $index);
        $link = $column->appendChild($dom->createElement('link', 'com.mysql.rdbms.mysql.datatype.' . strtolower($type)));
        $link->setAttribute('key', 'simpleType');
        if (isset($attributes['userType'])) {
            $link = $column->appendChild($dom->createElement('link', 'com.mysql.rdbms.mysql.userdatatype.' . $attributes['userType']));
            $link->setAttribute('key', 'userType');
        }
        foreach (array_replace(['length' => '-1', 'precision' => '-1', 'scale' => '-1'], $attributes) as $key => $text) {
            if (!in_array($key, ['userType', 'flags'], true)) {
                $value($column, $key, $text);
            }
        }
        $flags = $value($column, 'flags', '');
        foreach ($attributes['flags'] ?? [] as $flag) {
            $value($flags, '', $flag)->setAttribute('type', 'string');
        }
        $value($column, 'defaultValueIsNull', '1');
    }
    $file = $directory . '/types.mwb';
    $zip = new ZipArchive();
    check($zip->open($file, ZipArchive::CREATE) === true, 'Criar fixture de parâmetros de tipos');
    $zip->addFromString('document.mwb.xml', $dom->saveXML());
    $zip->close();

    $model = parseMwb($file);
    $database = $model;
    foreach ($cases as $index => [$type, $attributes, $expected, $different]) {
        check($model['type_parameters']['columns']['field_' . $index]['type'] === $expected, 'Parâmetros lidos do MWB: ' . $expected);
        $database['type_parameters']['columns']['field_' . $index]['type'] = normalizeSqlType(strtolower($expected));
    }
    check(compareSchemas($database, $model)['differences'] === [], 'Parâmetros iguais não geram diferenças');
    foreach ($cases as $index => [$type, $attributes, $expected, $different]) {
        $database['type_parameters']['columns']['field_' . $index]['type'] = normalizeSqlType($different);
    }
    $report = compareSchemas($database, $model);
    check(count($report['differences']) === count($cases), 'Detecta cada alteração de tamanho/precisão/tipo');
    $text = renderTextReport($report);
    foreach ($cases as $index => [$type, $attributes, $expected, $different]) {
        $difference = $report['tables']['type_parameters']['differences'][array_search('field_' . $index, array_column($report['tables']['type_parameters']['differences'], 'column'), true)];
        check($difference['type'] === 'column_type' && $difference['model'] === $expected && $difference['database'] === $different, 'Relata os dois tipos completos: ' . $expected);
        check(str_contains($text, 'Modelo : ' . $expected) && str_contains($text, 'Banco  : ' . $different), 'Relatório preserva os parâmetros');
    }
    check(normalizePostgresType('timestamp(3) without time zone') === 'DATETIME(3)', 'Precisão temporal do PostgreSQL preservada');
    check(normalizePostgresType('character varying(40)') !== normalizePostgresType('character varying(80)'), 'Tamanho VARCHAR do PostgreSQL comparado');
}
