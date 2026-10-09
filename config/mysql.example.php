<?php
// Template: copy this file to config/mysql.php and fill in your own values.
// (config/mysql.php is in .gitignore, so your real credentials never reach GitHub.)
$mysql_host = 'localhost';
$mysql_user = 'root';
$mysql_password = '';
$mysql_dbname = 'manga_collection';

try {
    $pdo = new PDO(
        "mysql:host=$mysql_host;dbname=$mysql_dbname;charset=utf8mb4",
        $mysql_user,
        $mysql_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    // Never show the real message (host, user…) to visitors: it goes to the PHP error log
    error_log('MangaTracker DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur de connexion à la base de données.');
}
?>
