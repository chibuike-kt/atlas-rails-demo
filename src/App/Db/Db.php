<?php
declare(strict_types=1);

namespace App\Db;

use PDO;

final class Db {
  public static function pdo(): PDO {
    $pdo = new PDO('sqlite:storage/demo.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");
    return $pdo;
  }
}
