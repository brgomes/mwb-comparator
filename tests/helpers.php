<?php

require_once dirname(__DIR__) . '/index.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Um modelo MWB mínimo real (ZIP/XML), independente de arquivos do usuário. */
function writeTestModel(string $path): void
{
    $spec = [
        'accounts' => [
            'columns' => [
                'tenant_id' => ['INT', null, null, false, null],
                'id' => ['BIGINT', null, null, false, null],
                'username' => ['VARCHAR', '80', null, false, "'guest'"],
                'balance' => ['DECIMAL', '12', '2', false, '100.00'],
                'enabled' => ['INT', null, null, false, '0'],
                'occurred_at' => ['DATETIME', null, null, true, 'CURRENT_TIMESTAMP'],
            ],
            'pk' => ['id', 'tenant_id'],
        ],
        'drift' => [
            'columns' => [
                'id' => ['INT', null, null, false, null],
                'amount' => ['INT', null, null, true, '10'],
                'name' => ['VARCHAR', '80', null, false, "'model'"],
                'only_model' => ['TEXT', null, null, true, null],
            ],
            'pk' => ['name', 'id'],
        ],
        'model_only' => ['columns' => ['id' => ['INT', null, null, false, null]], 'pk' => ['id']],
    ];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $root = $dom->appendChild($dom->createElement('data'));
    $value = static function (DOMElement $parent, string $key, string $text) use ($dom): DOMElement {
        $node = $parent->appendChild($dom->createElement('value'));
        $node->setAttribute('key', $key);
        $node->appendChild($dom->createTextNode($text));

        return $node;
    };
    foreach ($spec as $name => $table) {
        $node = $root->appendChild($dom->createElement('value'));
        $node->setAttribute('struct-name', 'db.mysql.Table');
        $value($node, 'name', $name);
        $columns = $value($node, 'columns', '');
        foreach ($table['columns'] as $column => [$type, $length, $scale, $nullable, $default]) {
            $item = $columns->appendChild($dom->createElement('value'));
            $item->setAttribute('type', 'object');
            $item->setAttribute('struct-name', 'db.mysql.Column');
            $item->setAttribute('id', "{$name}.{$column}");
            $value($item, 'name', $column);
            $link = $item->appendChild($dom->createElement('link', 'com.mysql.rdbms.mysql.datatype.' . strtolower($type)));
            $link->setAttribute('key', 'simpleType');
            $value($item, $type === 'DECIMAL' ? 'precision' : 'length', $length ?? '');
            $value($item, 'scale', $scale ?? '');
            $value($item, 'isNotNull', $nullable ? '0' : '1');
            $value($item, 'defaultValue', $default ?? '');
            $value($item, 'defaultValueIsNull', $default === null ? '1' : '0');
        }
        $indices = $value($node, 'indices', '');
        $index = $indices->appendChild($dom->createElement('value'));
        $index->setAttribute('type', 'object');
        $index->setAttribute('struct-name', 'db.mysql.Index');
        $value($index, 'isPrimary', '1');
        $pkColumns = $value($index, 'columns', '');
        foreach ($table['pk'] as $column) {
            $item = $pkColumns->appendChild($dom->createElement('value'));
            $item->setAttribute('type', 'object');
            $item->setAttribute('struct-name', 'db.mysql.IndexColumn');
            $link = $item->appendChild($dom->createElement('link', "{$name}.{$column}"));
            $link->setAttribute('key', 'referencedColumn');
        }
    }
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Criar MWB de teste');
    $zip->addFromString('document.mwb.xml', $dom->saveXML());
    $zip->close();
}

function runCli(string $config, string $name = 'test'): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/index.php', $name, $config], [
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}
