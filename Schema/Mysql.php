<?php

namespace Kanboard\Plugin\ExcelTemplate\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS excel_templates (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            filename_pattern VARCHAR(255),
            combine_mode VARCHAR(10) NOT NULL DEFAULT 'rows',
            project_ids TEXT,
            file_key VARCHAR(255) NOT NULL,
            file_name VARCHAR(255),
            mappings MEDIUMTEXT,
            created_at BIGINT DEFAULT 0,
            updated_at BIGINT DEFAULT 0,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB CHARSET=utf8mb4
    ");
}
