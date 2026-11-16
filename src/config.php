<?php

/** Carrega somente a entrada selecionada no array retornado pelo PHP. */
function readConfigEntry(string $file, string $name): array
{
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
        throw new RuntimeException('Informe um nome de configuração válido, como futcentre.');
    }

    $path = realpath($file);
    if ($path === false || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Arquivo de configuração não encontrado ou sem permissão de leitura. Copie config.example.php para config.php.');
    }

    try {
        $configurations = (static function (string $path) {
            return require $path;
        })($path);
    } catch (Throwable $exception) {
        throw new RuntimeException('Não foi possível carregar o arquivo de configuração PHP. Verifique a sintaxe e o retorno do array.');
    }
    if (!is_array($configurations)) {
        throw new RuntimeException('O arquivo de configuração PHP deve retornar um array.');
    }
    if (!array_key_exists($name, $configurations)) {
        throw new RuntimeException("Configuração '{$name}' não encontrada no array de configuração.");
    }
    $config = $configurations[$name];
    if (!is_array($config)) {
        throw new RuntimeException("A configuração '{$name}' deve ser um array.");
    }

    return $config;
}

function loadConfig(string $file, string $name): array
{
    $config = readConfigEntry($file, $name);
    $path = realpath($file);
    $database = $config['database'] ?? [];
    if (is_array($database) && isset($database['port']) && is_int($database['port'])) {
        $database['port'] = (string) $database['port'];
    }
    foreach (['driver', 'host', 'port', 'username', 'password', 'name'] as $key) {
        if (!is_array($database) || !array_key_exists($key, $database) || !is_string($database[$key])) {
            throw new RuntimeException("Informe database.{$key} no arquivo de configuração.");
        }
        if ($key !== 'password') {
            $database[$key] = trim($database[$key]);
            if ($database[$key] === '') {
                throw new RuntimeException("Informe database.{$key} no arquivo de configuração.");
            }
        }
    }

    $driver = strtolower($database['driver']);
    $database['driver'] = match ($driver) {
        'mysql', 'mariadb' => 'mysql',
        'pgsql', 'postgres', 'postgresql' => 'pgsql',
        default => throw new RuntimeException('Driver não suportado. Use mysql, mariadb ou postgres.'),
    };

    if (!ctype_digit($database['port']) || (int) $database['port'] < 1 || (int) $database['port'] > 65535) {
        throw new RuntimeException('database.port deve ser um número entre 1 e 65535.');
    }

    // O PDO usa ponto e vírgula como separador de parâmetros no DSN.
    foreach (['host', 'name'] as $key) {
        if (preg_match('/[;\x00\r\n]/', $database[$key])) {
            throw new RuntimeException("database.{$key} contém caracteres inválidos para a conexão.");
        }
    }

    $schema = $database['schema'] ?? 'public';
    if (!is_string($schema) || trim($schema) === '') {
        throw new RuntimeException('database.schema deve conter o nome do schema PostgreSQL.');
    }
    $database['schema'] = trim($schema);

    $directory = dirname($path);
    $model = configuredModelPath($config, $directory);
    if (!is_file($model) || !is_readable($model)) {
        throw new RuntimeException('Arquivo MWB não encontrado ou sem permissão de leitura. Verifique model.path e, no Docker, a montagem da pasta.');
    }

    $report = $config['report']['path'] ?? $name . '.txt';
    if (!is_string($report) || trim($report) === '') {
        throw new RuntimeException('report.path deve conter o caminho do relatório TXT.');
    }
    $report = resolveConfigPath(trim($report), $directory);
    $reportDirectory = realpath(dirname($report));
    if ($reportDirectory === false || !is_writable($reportDirectory) || (file_exists($report) && !is_writable($report))) {
        throw new RuntimeException('A pasta do relatório precisa existir e permitir escrita. Verifique report.path.');
    }
    // Evita sobrescrever a configuração, o modelo ou qualquer outro tipo de arquivo.
    if (strtolower(pathinfo($report, PATHINFO_EXTENSION)) !== 'txt'
        || is_link($report)
        || realpath($report) === $path
        || (realpath($report) !== false && realpath($report) === realpath($model))) {
        throw new RuntimeException('report.path deve apontar para um arquivo .txt próprio para o relatório.');
    }

    return [
        'database' => $database,
        'model' => $model,
        'report' => $report,
        'docker' => ['network' => configuredDockerNetwork($config)],
        'ignore_tables' => configuredIgnoredTables($config),
    ];
}

function configuredIgnoredTables(array $config): array
{
    $tables = $config['ignore_tables'] ?? [];
    if (!is_array($tables) || !array_is_list($tables)) {
        throw new RuntimeException('ignore_tables deve ser uma lista de nomes de tabelas.');
    }
    foreach ($tables as &$table) {
        if (!is_string($table) || trim($table) === '') {
            throw new RuntimeException('Cada item de ignore_tables deve ser um nome de tabela não vazio.');
        }
        $table = strtolower(trim($table));
    }
    unset($table);

    return array_values(array_unique($tables));
}

function configuredDockerNetwork(array $config): string
{
    $docker = $config['docker'] ?? [];
    if (!is_array($docker)) {
        throw new RuntimeException('docker deve ser um array com a opção network.');
    }
    $network = $docker['network'] ?? 'host';
    if (!is_string($network)) {
        throw new RuntimeException('docker.network deve ser o nome de uma rede Docker.');
    }
    $network = trim($network);
    if ($network === '') {
        return 'host';
    }
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/', $network)) {
        throw new RuntimeException('docker.network contém um nome de rede inválido.');
    }

    return $network;
}

function configuredModelPath(array $config, string $directory): string
{
    $model = $config['model']['path'] ?? null;
    if (!is_string($model) || trim($model) === '') {
        throw new RuntimeException('Informe model.path com o caminho do arquivo .mwb.');
    }

    return resolveConfigPath(trim($model), $directory);
}

function resolveConfigPath(string $path, string $directory): string
{
    if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) || str_starts_with($path, '\\\\')) {
        return $path;
    }

    return $directory . DIRECTORY_SEPARATOR . $path;
}
