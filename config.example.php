<?php

// Copie para config.php e preencha os dados reais.
// Cada chave seleciona uma comparação: make exec projeto_a / make exec projeto_b.
return [
    'projeto_a' => [
        // Opcional: nomes exatos, sem diferenciar maiúsculas de minúsculas.
        'ignore_tables' => [],
        'docker' => [
            // 'host' usa as portas publicadas no host; ou informe a rede do banco.
            // Em uma rede compartilhada, use o nome do contêiner do banco em database.host.
            'network' => 'host',
        ],
        'database' => [
            // mysql / mariadb / postgres / postgresql / pgsql
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'seu_usuario',
            'password' => '',
            'name' => 'banco_exemplo',
            // Usado apenas no PostgreSQL (porta usual: 5432).
            'schema' => 'public',
        ],
        'model' => [
            // Caminho absoluto no HD ou relativo à pasta deste config.php.
            'path' => '/caminho/modelagens/projeto_a.mwb',
        ],
        // Opcional. Sem report, o nome da configuração define o TXT.
        'report' => [
            'path' => 'projeto_a.txt',
        ],
    ],

    'projeto_b' => [
        'ignore_tables' => [
            'jobs',
            'migrations',
            'notifications',
        ],
        'docker' => [
            'network' => 'host',
        ],
        'database' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'seu_usuario',
            'password' => 'sua_senha',
            'name' => 'projeto_b',
        ],
        'model' => [
            'path' => '/caminho/modelagens/projeto_b.mwb',
        ],
    ],
];
