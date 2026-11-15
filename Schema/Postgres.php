<?php

namespace Kanboard\Plugin\ExcelTemplate\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS excel_templates (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            filename_pattern VARCHAR(255),
            combine_mode VARCHAR(10) NOT NULL DEFAULT 'rows',
            project_ids TEXT,
            file_key VARCHAR(255) NOT NULL,
            file_name VARCHAR(255),
            mappings TEXT,
            created_at BIGINT DEFAULT 0,
            updated_at BIGINT DEFAULT 0
        )
    ");
}
