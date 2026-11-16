<?php

require_once __DIR__ . '/helpers.php';

$ran = 0;
foreach (['TEST_MYSQL_CONFIG', 'TEST_PGSQL_CONFIG'] as $variable) {
    $file = getenv($variable);
    if (!$file) {
        continue;
    }
    $name = $variable === 'TEST_MYSQL_CONFIG' ? 'mysql' : 'postgres';
    $config = loadConfig($file, $name);
    $database = readDatabaseSchema($config['database']);
    check(count($database) === 3 && !isset($database['ignored_view']), 'Somente tabelas do banco/schema selecionado');
    check($database['accounts']['primaryKey'] === ['id', 'tenant_id'], 'Chave composta na ordem declarada');
    check($database['accounts']['columns']['id']['default'] === null, 'AUTO_INCREMENT/serial ignorado');
    check($database['accounts']['columns']['balance']['default'] === '100', 'Default decimal preservado');
    check($database['drift']['columns']['amount']['default'] === '100', 'Default inteiro preservado');

    $report = compareSchemas($database, parseMwb($config['model']));
    // O modelo de teste omite larguras de inteiros; MariaDB pode reportá-las.
    foreach ($report['tables']['accounts']['differences'] as $difference) {
        check($config['database']['driver'] === 'mysql'
            && $difference['type'] === 'column_type'
            && preg_match('/^(INT|BIGINT)\(\d+\)$/', $difference['database']) === 1
            && $difference['model'] === preg_replace('/\(\d+\)/', '', $difference['database']),
            'Somente larguras de inteiros implícitas podem diferir na tabela equivalente: ' . json_encode($difference));
    }
    check($report['tables']['database_only']['status'] === 'missing_model', 'Tabela ausente do modelo');
    check($report['tables']['model_only']['status'] === 'missing_database', 'Tabela ausente do banco');
    $types = array_column($report['tables']['drift']['differences'], 'type');
    foreach (['column_type', 'column_nullable', 'column_default', 'column_position', 'column_database_only', 'column_model_only', 'primary_key'] as $type) {
        check(in_array($type, $types, true), 'Detectar diferença: ' . $type);
    }

    [$exit, $output] = runCli($file, $name);
    check($exit === 0, 'Diferenças retornam sucesso: ' . $output);
    check(is_file($config['report']), 'Relatório criado');
    $saved = file_get_contents($config['report']);
    check(str_contains($saved, 'COMPARAÇÃO DA MODELAGEM') && str_contains($output, $saved), 'Mesmo relatório no terminal e TXT');
    check(!str_contains($saved, $config['database']['password']), 'Relatório sem senha');

    if ($config['database']['driver'] === 'pgsql') {
        $missingSchema = $config['database'];
        $missingSchema['schema'] = 'schema_que_nao_existe';
        try {
            readDatabaseSchema($missingSchema);
            throw new LogicException('Schema inexistente foi aceito');
        } catch (RuntimeException $exception) {
            check(str_contains($exception->getMessage(), 'schema'), 'Erro de schema inexistente');
        }
    }
    echo "OK: integração {$config['database']['driver']} e relatório CLI.\n";
    $ran++;
}

if ($ran === 0) {
    fwrite(STDERR, "Informe TEST_MYSQL_CONFIG e/ou TEST_PGSQL_CONFIG para um banco descartável com os fixtures.\n");
    exit(1);
}
