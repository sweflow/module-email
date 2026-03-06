<?php
return function (PDO $db) {
    $db->exec("
        INSERT INTO emails (to_email, subject, body)
        VALUES ('demo@example.com', 'Bem-vindo', '<p>Bem-vindo à plataforma.</p>')
    ");
};
