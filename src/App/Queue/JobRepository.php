<?php
declare(strict_types=1);

namespace App\Queue;

use App\Db\Db;
use App\Support\Util;
use PDO;

final class JobRepository {

  public function enqueue(string $correlationId, string $type, array $payload, int $delaySeconds = 0, int $maxAttempts = 8): string {
    $pdo = Db::pdo();
    $id = Util::uuid();
    $now = Util::now();
    $runAt = (new \DateTimeImmutable('now'))->modify('+' . max(0,$delaySeconds) . ' seconds')->format(DATE_ATOM);

    $stmt = $pdo->prepare(
      "INSERT INTO jobs (id, correlation_id, type, payload_json, status, attempt, max_attempts, run_at, created_at, updated_at)
       VALUES (?, ?, ?, ?, 'queued', 0, ?, ?, ?, ?)"
    );
    $stmt->execute([$id, $correlationId, $type, Util::jsonEncode($payload), $maxAttempts, $runAt, $now, $now]);
    return $id;
  }

  public function fetchAndLockNext(): ?array {
    $pdo = Db::pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
      "SELECT * FROM jobs
       WHERE status='queued' AND run_at <= ?
       ORDER BY run_at ASC
       LIMIT 1"
    );
    $stmt->execute([Util::now()]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
      $pdo->commit();
      return null;
    }

    $upd = $pdo->prepare(
      "UPDATE jobs
       SET status='running', locked_at=?, updated_at=?
       WHERE id=? AND status='queued'"
    );
    $upd->execute([Util::now(), Util::now(), $job['id']]);

    // re-read to confirm lock
    $chk = $pdo->prepare("SELECT * FROM jobs WHERE id=? LIMIT 1");
    $chk->execute([$job['id']]);
    $locked = $chk->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();
    return $locked ?: null;
  }

  public function markSucceeded(string $jobId): void {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("UPDATE jobs SET status='succeeded', updated_at=? WHERE id=?");
    $stmt->execute([Util::now(), $jobId]);
  }

  public function markFailedAndRetry(string $jobId, int $attempt, int $maxAttempts, string $error): void {
    $pdo = Db::pdo();

    $attemptNext = $attempt + 1;
    $now = new \DateTimeImmutable('now');

    if ($attemptNext >= $maxAttempts) {
      $stmt = $pdo->prepare(
        "UPDATE jobs SET status='dead', attempt=?, last_error=?, updated_at=? WHERE id=?"
      );
      $stmt->execute([$attemptNext, $error, Util::now(), $jobId]);
      return;
    }

    // Exponential backoff: 2^attempt seconds, capped at 5 minutes
    $delay = min(300, (int)pow(2, max(0, $attempt)));
    $runAt = $now->modify('+' . $delay . ' seconds')->format(DATE_ATOM);

    $stmt = $pdo->prepare(
      "UPDATE jobs
       SET status='queued', attempt=?, last_error=?, run_at=?, updated_at=?
       WHERE id=?"
    );
    $stmt->execute([$attemptNext, $error, $runAt, Util::now(), $jobId]);
  }

  public function listByCorrelation(string $correlationId): array {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE correlation_id=? ORDER BY created_at ASC");
    $stmt->execute([$correlationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
