<?php
declare(strict_types=1);

namespace App\Db;

use PDO;

final class Db {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (self::$pdo) return self::$pdo;

    $root = dirname(__DIR__, 3);
    $path = $root . '/storage/demo.sqlite';
    @mkdir($root . '/storage', 0777, true);

    $pdo = new PDO('sqlite:' . $path, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Concurrency + reliability pragmas
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA synchronous=NORMAL;');
    $pdo->exec('PRAGMA busy_timeout=5000;'); // wait up to 5s if locked

    self::$pdo = $pdo;
    return $pdo;
  }
}
