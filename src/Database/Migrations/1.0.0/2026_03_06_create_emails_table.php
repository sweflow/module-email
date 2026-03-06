<?php
return [
    'up' => function (PDO $db) {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS emails (
                    id SERIAL PRIMARY KEY,
                    to_email VARCHAR(255),
                    subject VARCHAR(255),
                    body TEXT,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS emails (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    to_email VARCHAR(255),
                    subject VARCHAR(255),
                    body TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
    },
    'down' => function (PDO $db) {
        $db->exec("DROP TABLE IF EXISTS emails");
    }
];
