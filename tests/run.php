<?php

require __DIR__ . '/helpers.php';
require __DIR__ . '/types.php';

$directory = sys_get_temp_dir() . '/mwb-comparator-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    checkTypeParameters($directory);
    writeTestModel($directory . '/model.mwb');
    $entry = [
        'database' => [
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'tester',
            'password' => 'p$a;#ss!null',
            'name' => 'example',
        ],
        'model' => ['path' => 'model.mwb'],
    ];
    $configPath = $directory . '/config.php';
    $write = static function (array $entries) use ($configPath): void {
        file_put_contents($configPath, "<?php\nreturn " . var_export($entries, true) . ";\n");
    };
    $write(['test' => $entry, 'invalid_other' => null]);
    $config = loadConfig($configPath, 'test');
    check($config['database']['driver'] === 'mysql', 'Alias MariaDB');
    check($config['database']['password'] === 'p$a;#ss!null', 'Senha deve permanecer literal');
    check($config['model'] === $directory . '/model.mwb', 'Modelo relativo ao PHP');
    check($config['report'] === $directory . '/test.txt', 'Relatório padrão pelo nome da configuração');
    check($config['docker']['network'] === 'host', 'Rede host quando não configurada');
    check($config['ignore_tables'] === [], 'Lista opcional não ignora tabelas por padrão');
    check(count(parseMwb($config['model'])) === 3, 'Leitura ZIP/XML do MWB');
    check(parseMwb($config['model'])['accounts']['primaryKey'] === ['id', 'tenant_id'], 'Ordem da chave composta no MWB');

    $other = $entry;
    $other['database']['name'] = 'another_database';
    $other['model']['path'] = $directory . '/model.mwb';
    $other['report']['path'] = 'another.txt';
    $other['docker']['network'] = 'rede_exemplo';
    $other['ignore_tables'] = [' JOBS ', 'jobs', 'migrations'];
    $write(['test' => $entry, 'other' => $other]);
    $selected = loadConfig($configPath, 'other');
    check($selected['database']['name'] === 'another_database', 'Conexão da entrada selecionada');
    check($selected['report'] === $directory . '/another.txt', 'Relatório da entrada selecionada');
    check($selected['docker']['network'] === 'rede_exemplo', 'Rede Docker da entrada selecionada');
    check($selected['ignore_tables'] === ['jobs', 'migrations'], 'Normaliza nomes e remove duplicados da lista');
    check(loadConfig($configPath, 'test')['ignore_tables'] === [], 'Lista de uma configuração não afeta outra');
    check(configuredIgnoredTables(['ignore_tables' => null]) === [], 'Lista nula é opcional');
    check(loadConfig($configPath, 'test')['docker']['network'] === 'host', 'Configurações mantêm redes independentes');
    foreach ([null, '', '   '] as $network) {
        check(configuredDockerNetwork(['docker' => ['network' => $network]]) === 'host', 'Rede opcional usa host');
    }
    foreach (['postgres', 'postgresql', 'pgsql'] as $alias) {
        $changed = $entry;
        $changed['database']['driver'] = $alias;
        $write(['test' => $changed]);
        check(loadConfig($configPath, 'test')['database']['driver'] === 'pgsql', 'Alias PostgreSQL');
    }
    foreach (['', 'null', 'a"b', 'a\\b'] as $password) {
        $changed = $entry;
        $changed['database']['password'] = $password;
        $write(['test' => $changed]);
        check(loadConfig($configPath, 'test')['database']['password'] === $password, 'Senha vazia, reservada ou com escapes');
    }

    $invalid = [];
    foreach (['jobs', [''], [123], [[]], ['jobs' => true]] as $tables) {
        $changed = $entry;
        $changed['ignore_tables'] = $tables;
        $invalid[] = $changed;
    }
    foreach ([['network' => []], ['network' => "rede\noutra"], ['network' => 'rede com espaços'], 'rede'] as $docker) {
        $changed = $entry;
        $changed['docker'] = $docker;
        $invalid[] = $changed;
    }
    foreach (['driver' => 'sqlite', 'port' => 0, 'host' => 'localhost;dbname=other'] as $key => $value) {
        $changed = $entry;
        $changed['database'][$key] = $value;
        $invalid[] = $changed;
    }
    foreach ([65536, [], 3306.5] as $port) {
        $changed = $entry;
        $changed['database']['port'] = $port;
        $invalid[] = $changed;
    }
    foreach (['username', 'password'] as $key) {
        $changed = $entry;
        unset($changed['database'][$key]);
        $invalid[] = $changed;
    }
    $changed = $entry;
    $changed['model']['path'] = 'missing.mwb';
    $invalid[] = $changed;
    foreach (['config.php', 'missing/report.txt'] as $report) {
        $changed = $entry;
        $changed['report']['path'] = $report;
        $invalid[] = $changed;
    }
    foreach ($invalid as $index => $changed) {
        $write(['test' => $changed]);
        try {
            loadConfig($configPath, 'test');
            throw new LogicException("Configuração inválida aceita: {$index}");
        } catch (RuntimeException $exception) {
            check(!str_contains($exception->getMessage(), 'p$a;#ss!null'), 'Erro não pode expor a senha');
        }
    }
    foreach (["<?php return 'not an array';", "<?php return ['test' => null];", "<?php return [];", "<?php return [ syntax error segredo;"] as $source) {
        file_put_contents($configPath, $source);
        [$exit, $output] = runCli($configPath);
        check($exit === 1 && str_contains($output, 'ERRO:'), 'Retorno inválido, nome ausente ou sintaxe inválida na CLI');
        check(!str_contains($output, 'segredo'), 'Erro de PHP não pode expor o conteúdo da configuração');
    }
    $write(['test' => $entry]);
    [$exit, $output] = runCli($configPath, 'unknown');
    check($exit === 1 && str_contains($output, "Configuração 'unknown' não encontrada"), 'Nome desconhecido na CLI');

    foreach (['100' => '100', '100.00' => '100', '10.50' => '10.5', '-0.00' => '0', '0010' => '10', '.50' => '0.5'] as $input => $expected) {
        $normalized = normalizeDefault((string) $input);
        check($normalized === $expected && normalizeDefault($normalized) === $expected, 'Defaults numéricos devem preservar zeros inteiros');
    }
    check(!defaultsEqual('100', '10'), '100 e 10 são defaults diferentes');
    check(defaultsEqualForColumn(null, 'NULL', true, true), 'NULL implícito e explícito em nullable');
    check(normalizePostgresType('character varying(80)') === 'VARCHAR(80)', 'VARCHAR PostgreSQL');
    check(normalizePostgresType('numeric(12,2)') === 'DECIMAL(12,2)', 'DECIMAL PostgreSQL');
    check(normalizePostgresType('timestamp without time zone') === 'DATETIME', 'DATETIME PostgreSQL');
    check(normalizePostgresType('timestamp with time zone') !== 'DATETIME', 'Não esconder fuso horário');
    check(normalizePostgresDefault("'guest'::character varying") === 'guest', 'Default literal PostgreSQL');
    check(normalizePostgresDefault('now()') === 'CURRENT_TIMESTAMP', 'Default de timestamp');

    $model = parseMwb($directory . '/model.mwb');
    check(compareSchemas($model, $model)['differences'] === [], 'Modelo idêntico');
    $database = $model;
    $database['accounts']['columns']['created_at'] = ['type' => 'DATETIME', 'nullable' => true, 'default' => null];
    check(compareSchemas($database, $model)['differences'] === [], 'Auditoria ignorada');
    $database['accounts']['columns'] = ['new_column' => ['type' => 'INT', 'nullable' => true, 'default' => null]] + $database['accounts']['columns'];
    check(count(compareSchemas($database, $model)['differences']) === 1, 'Coluna extra não provoca cascata de posições');

    $database = $model;
    unset($database['model_only']);
    $database['database_only'] = ['name' => 'database_only', 'columns' => [], 'primaryKey' => []];
    $database['drift']['columns']['name']['type'] = 'VARCHAR(40)';
    $ignored = configuredIgnoredTables(['ignore_tables' => ['DRIFT', 'model_only', 'database_only', 'missing_table']]);
    $filtered = compareSchemas($database, $model, $ignored);
    check(array_keys($filtered['tables']) === ['accounts'], 'Exclui tabelas de ambos os lados e somente as selecionadas');
    check($filtered['differences'] === [], 'Exclui diferenças internas e tabelas presentes em apenas um lado');
    $text = renderTextReport($filtered);
    check(!str_contains($text, 'drift') && !str_contains($text, 'model_only') && !str_contains($text, 'database_only'), 'Tabelas ignoradas não aparecem no relatório');
    check(str_contains($text, 'Diferenças encontradas: 0'), 'Resumo conta somente as diferenças consideradas');
    check(compareSchemas($database, $model, []) === compareSchemas($database, $model), 'Lista vazia mantém comparação completa');
    check(count(compareSchemas($database, $model)['differences']) === 3, 'Sem lista, diferenças continuam sendo detectadas');
    $allIgnored = compareSchemas($database, $model, ['accounts', 'drift', 'model_only', 'database_only']);
    check($allIgnored['tables'] === [] && $allIgnored['differences'] === [], 'Todas as tabelas podem ser ignoradas');

    // Porta TCP reservada e sem listen: falha rápida, sem depender de servidor externo.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    check($socket !== false, 'Reservar porta de teste');
    $port = explode(':', stream_socket_get_name($socket, false))[1];
    $changed = $entry;
    $changed['database']['port'] = $port;
    foreach (['mysql', 'postgres'] as $driver) {
        $changed['database']['driver'] = $driver;
        $write(['test' => $changed]);
        try {
            readDatabaseSchema(loadConfig($configPath, 'test')['database']);
            throw new LogicException('Conexão com porta fechada foi aceita');
        } catch (PDOException $exception) {
            $expected = $exception->getMessage();
        }
        [$exit, $output] = runCli($configPath);
        check($exit === 1, 'Falha de conexão retorna erro');
        check($output === 'ERRO: ' . $expected . PHP_EOL, 'CLI exibe a mensagem original do PDO para ' . $driver);
        check(str_contains($output, 'SQLSTATE'), 'Código SQLSTATE preservado');
    }
    check(!file_exists($directory . '/test.txt'), 'Falha não gera relatório');
    fclose($socket);
    [$exit, $output] = runCli($directory . '/missing.php');
    check($exit === 1 && str_contains($output, 'configuração'), 'Arquivo ausente na CLI');

    echo "OK: configuração, MWB, normalização, comparação e falhas da CLI.\n";
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
