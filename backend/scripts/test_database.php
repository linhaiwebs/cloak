<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\DatabaseHelper;

$dataDir = __DIR__ . '/../data';
$db = new DatabaseHelper($dataDir);
$pdo = $db->getConnection();

echo "Testing database structure...\n\n";

$tables = ['clicks', 'conversion_sessions', 'conversions'];

foreach ($tables as $table) {
    echo "Table: $table\n";
    echo str_repeat('-', 50) . "\n";

    $stmt = $pdo->query("PRAGMA table_info($table)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($columns)) {
        echo "ERROR: Table not found!\n\n";
        continue;
    }

    foreach ($columns as $col) {
        echo "  {$col['name']} ({$col['type']})";
        if ($col['notnull']) echo " NOT NULL";
        if ($col['dflt_value'] !== null) echo " DEFAULT {$col['dflt_value']}";
        if ($col['pk']) echo " PRIMARY KEY";
        echo "\n";
    }

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "  Records: $count\n";
    echo "\n";
}

$stmt = $pdo->query("PRAGMA index_list(conversion_sessions)");
$indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Indexes on conversion_sessions: " . count($indexes) . "\n";
foreach ($indexes as $idx) {
    echo "  - {$idx['name']}\n";
}
echo "\n";

$stmt = $pdo->query("PRAGMA index_list(conversions)");
$indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Indexes on conversions: " . count($indexes) . "\n";
foreach ($indexes as $idx) {
    echo "  - {$idx['name']}\n";
}

echo "\nDatabase structure test completed successfully!\n";
