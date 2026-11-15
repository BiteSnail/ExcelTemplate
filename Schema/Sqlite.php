<?php

namespace Kanboard\Plugin\ExcelTemplate\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS excel_templates (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT,
            filename_pattern TEXT,
            combine_mode TEXT NOT NULL DEFAULT 'rows',
            project_ids TEXT,
            file_key TEXT NOT NULL,
            file_name TEXT,
            mappings TEXT,
            created_at INTEGER DEFAULT 0,
            updated_at INTEGER DEFAULT 0
        )
    ");
}
