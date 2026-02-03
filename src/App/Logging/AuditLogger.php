<?php
declare(strict_types=1);

namespace App\Logging;

use App\Db\Db;
use App\Support\Util;

final class AuditLogger {
  public function log(string $correlationId, string $actor, string $action, array $data): void {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare(
      "INSERT INTO audit_logs (id, correlation_id, actor, action, data_json, created_at)
       VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
      Util::uuid(),
      $correlationId,
      $actor,
      $action,
      Util::jsonEncode($data),
      Util::now()
    ]);
  }
}
