<?php
$config = require __DIR__ . '/../config.php';
try {
  $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
  $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) { die("DB connection failed: " . $e->getMessage()); }

/* auto-migrate (safety) */
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    meta JSON NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (ticket_id), INDEX (action)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $dbName = $config['db']['name'];
  $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='avatar'");
  $chk->execute([$dbName]);
  if (!$chk->fetchColumn()) { $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER phone"); }
  @mkdir(__DIR__ . '/../public/uploads', 0777, true);
  @mkdir(__DIR__ . '/../public/uploads/avatars', 0777, true);
  @mkdir(__DIR__ . '/../public/uploads/branding', 0777, true);
} catch (Exception $e) {}