<?php
declare(strict_types=1);

namespace App\Idempotency;

use App\Db\Db;
use App\Support\Util;
use PDO;

final class IdempotencyService {

  public function start(string $idemKey, string $route, string $requestHash): array {
    $pdo = Db::pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM idempotency_keys WHERE idempotency_key = ? AND route = ? LIMIT 1");
    $stmt->execute([$idemKey, $route]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      // Hash mismatch is a hard error: same key used for different request body
      if (($row['request_hash'] ?? '') !== $requestHash) {
        $pdo->rollBack();
        return ['status' => 'hash_mismatch', 'row' => $row];
      }

      // Completed: replay
      if (($row['status'] ?? '') === 'completed') {
        $pdo->rollBack();
        return ['status' => 'replay', 'row' => $row];
      }

      // Started/Failed: treat as in-progress (client should retry)
      $pdo->rollBack();
      return ['status' => 'in_progress', 'row' => $row];
    }

    $ins = $pdo->prepare(
      "INSERT INTO idempotency_keys (id, idempotency_key, route, request_hash, status, created_at)
       VALUES (?, ?, ?, ?, 'started', ?)"
    );
    $ins->execute([Util::uuid(), $idemKey, $route, $requestHash, Util::now()]);
    $pdo->commit();

    return ['status' => 'started'];
  }

  public function complete(string $idemKey, string $route, int $code, array $body): void {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare(
      "UPDATE idempotency_keys
       SET status='completed', response_code=?, response_body=?, completed_at=?
       WHERE idempotency_key=? AND route=?"
    );
    $stmt->execute([$code, Util::jsonEncode($body), Util::now(), $idemKey, $route]);
  }

  public function fail(string $idemKey, string $route, int $code, array $body): void {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare(
      "UPDATE idempotency_keys
       SET status='failed', response_code=?, response_body=?, completed_at=?
       WHERE idempotency_key=? AND route=?"
    );
    $stmt->execute([$code, Util::jsonEncode($body), Util::now(), $idemKey, $route]);
  }
}
