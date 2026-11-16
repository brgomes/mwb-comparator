# MWB Comparator

A plain PHP CLI tool that compares a **MySQL/MariaDB or PostgreSQL** database schema with a MySQL Workbench model (`.mwb`). It reads metadata only and generates a report in the terminal and as a TXT file. No `.sql` dump or local copy of the `.mwb` file in the project directory is required.

## Configuration

The `config.php` file returns an array of configurations, each identified by a key. Fill in the connection details in the existing entries or add your own. The names below are examples; use the keys defined in your `config.php` when running commands:

```php
<?php

return [
    'project_a' => [
        'ignore_tables' => [], // Optional, specific to this configuration.
        'docker' => [
            'network' => 'host', // Or the name of the database's Docker network.
        ],
        'database' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'your_username',
            'password' => 'your_password',
            'name' => 'project_a',
        ],
        'model' => [
            'path' => '/path/to/models/project_a.mwb',
        ],
        'report' => [
            'path' => 'project_a.txt',
        ],
    ],
    'project_b' => [
        // Another connection and model, using the same structure as above.
    ],
];
```

`make exec project_a` uses only `$config['project_a']`; `make exec project_b` uses `$config['project_b']`. Names are case-sensitive and may contain letters, numbers, periods, hyphens, and underscores, starting with a letter or number. Configurations that are not selected are neither validated nor connected to.

For PostgreSQL, use `'driver' => 'postgres'`, the server port (usually `5432`), and, if needed, `'schema' => 'public'` in the `database` array. Supported driver names are `mysql`, `mariadb`, `postgres`, `postgresql`, and `pgsql`. Metadata readers for other databases have not been implemented yet. The port may be an integer or a numeric string. Empty passwords are supported (`'password' => ''`); values follow standard PHP string syntax.

The model path may be absolute or relative to the configuration file's directory. Relative report paths are also resolved against that directory. The `report` array is optional: without it, the `project_a` configuration generates `project_a.txt` next to `config.php`. The destination directory must exist.

Use `ignore_tables` in each configuration entry to exclude tables from the comparison and report, on both the database and model sides. For example, inside `project_b`:

```php
'ignore_tables' => [
    'jobs',
    'migrations',
    'notifications',
],
```

This option is optional: when omitted, set to `null`, or set to `[]`, no tables are excluded. Table names must match exactly, ignoring case; patterns and wildcards are not supported. Add other tables as needed.

The local `config.php` is ignored by Git. In a fresh checkout, run `cp config.example.php config.php` to create it from the example without real credentials.

## Running with PHP

Requires PHP 8.1+ with `PDO`, `zip`, `dom`, and either the `pdo_mysql` or `pdo_pgsql` driver.

```bash
php index.php project_a                     # project_a entry in config.php
php index.php project_b                     # project_b entry in config.php
php index.php project_a /path/to/config.php  # alternative configuration file
```

The database is queried through the configured connection, without executing DDL or reading application table data. For MySQL/MariaDB, the user must be able to access the metadata of every table to be compared; tables hidden by permissions will not be listed.

## Running with Docker

The Makefile uses the local `local/php-apache:8.4` image, which must include the extensions listed above. You can change the image with `PHP_IMAGE=...`.

```bash
make exec project_a
make exec project_b
make exec CONFIG=project_a CONFIG_FILE=/path/to/config.php
```

The Makefile reads the model path from the selected entry and automatically mounts its directory at the same absolute path inside the container. If this directory differs from the configuration directory, it is mounted read-only. The configuration directory is writable so the report can be saved next to the configuration file. If the report is saved to another directory, run PHP locally or mount that directory manually in Docker. You can explicitly override model directory discovery with `MWB_DIR=/path/to/models`.

The Docker network is defined by `docker.network` in each entry of `config.php`. When omitted, set to `null`, or left empty, it defaults to `host`. On Linux, `host` lets you access databases through `127.0.0.1` and their published ports.

For example, if the database container publishes `127.0.0.1:3306->3306/tcp`, configure `'host' => '127.0.0.1'` and `'port' => 3306`, then run `make exec project_a`. If it publishes `3307->3306`, use port `3307` in this mode.

To connect through a shared Docker network, configure `'docker' => ['network' => 'network_name']`, set `database.host` to the **database** service or container name (for example, `'host' => 'database'`), and use the internal port `3306`. Run `make exec project_a`: the selected entry's network is used automatically and must already exist and include the database container. The application's PHP container name is not the database host unless the database server also runs inside it. The `docker.network` option controls the container created by the Makefile; running PHP locally uses the network of the PHP process itself.

## Comparison

- Tables and columns present only in the database or only in the model.
- Types, length, precision, `UNSIGNED`, nullability, default values, and primary keys (including column order in composite keys).
- Relative order of shared columns, without cascading differences caused by missing columns.
- Ignores secondary indexes, foreign keys, other constraints, auto-increment settings, and the audit columns `created_at`, `updated_at`, `created_by`, and `updated_by`.
- Compares names without case sensitivity, as in the original version.

Type parameters are compared as reported by the database and declared in the model: integer display widths (`TINYINT(1)` versus `TINYINT(4)`, as well as `SMALLINT`, `MEDIUMINT`, `INT`, and `BIGINT`), `BIT` widths, character and binary lengths, numeric precision and scale (`DECIMAL`, `FLOAT`, and `DOUBLE`), and fractional seconds precision for `TIME`, `DATETIME`, and `TIMESTAMP`. Differences appear under the column's type in the report. Integer display width describes formatting rather than storage capacity; the comparator still checks it to identify differences in schema definitions. Parameters omitted by the server are not inferred. See the [MySQL data type documentation](https://dev.mysql.com/doc/refman/8.4/en/data-types.html).

The `.mwb` file remains a MySQL Workbench model for MySQL. When comparing against PostgreSQL, direct equivalents such as `integer`/`INT`, `character varying`/`VARCHAR`, `numeric`/`DECIMAL`, and `timestamp without time zone`/`DATETIME` are normalized. PostgreSQL-specific types (such as arrays, `jsonb`, named enums, domains, and types with time zones) remain distinct from MySQL types. There is no general conversion between the two dialects. Defaults for identity/serial columns associated with sequences are ignored, just like MySQL auto-increment settings; other expressions are compared as text after basic normalization.

Differences return exit code `0`. Invalid configuration and connection, read, or write failures return `1`. Connection and query failures display the original PDO/database message, including SQLSTATE, with the CLI's `ERRO:` prefix.

## Tests

```bash
make test
php tests/run.php
```

Integration tests can run against **disposable databases**, prepared beforehand using the fixtures in `tests/fixtures/`. Define the `mysql` and `postgres` entries in PHP configuration files and pass their paths through the `TEST_MYSQL_CONFIG` and `TEST_PGSQL_CONFIG` environment variables:

```bash
TEST_MYSQL_CONFIG=/tmp/config.php TEST_PGSQL_CONFIG=/tmp/config.php php tests/integration.php
```

The integration test only queries the databases; fixtures must be loaded separately. Metadata references: [MySQL COLUMNS](https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html), [PostgreSQL pg_attribute](https://www.postgresql.org/docs/current/catalog-pg-attribute.html), and [pg_constraint](https://www.postgresql.org/docs/current/catalog-pg-constraint.html).
