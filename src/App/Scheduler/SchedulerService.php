<?php
declare(strict_types=1);

namespace App\Scheduler;

use App\Db\Db;
use App\Support\Util;
use App\Queue\JobRepository;
use PDO;

final class SchedulerService {

  public function tick(): int {
    $pdo = Db::pdo();
    $now = Util::now();

    $stmt = $pdo->prepare(
      "SELECT * FROM schedules
       WHERE status='active' AND next_run_at <= ?
       ORDER BY next_run_at ASC
       LIMIT 50"
    );
    $stmt->execute([$now]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return 0;

    $jobs = new JobRepository();
    $count = 0;

    foreach ($rows as $s) {
      $corr = 'sched_' . bin2hex(random_bytes(8));
      $jobs->enqueue($corr, 'rule_execute', [
        'user_id' => $s['user_id'],
        'rule_id' => $s['rule_id'],
        'schedule_id' => $s['id']
      ], 0, 8);
      $count++;

      // compute next run
      $next = $this->computeNextRun((string)$s['schedule_type'], (string)$s['schedule_value']);
      $upd = $pdo->prepare("UPDATE schedules SET next_run_at=?, updated_at=? WHERE id=?");
      $upd->execute([$next, Util::now(), $s['id']]);
    }

    return $count;
  }

  private function computeNextRun(string $type, string $value): string {
    $now = new \DateTimeImmutable('now');
    if ($type === 'interval_seconds') {
      $sec = max(5, (int)$value);
      return $now->modify('+' . $sec . ' seconds')->format(DATE_ATOM);
    }

    // daily_at HH:MM
    if ($type === 'daily_at') {
      $hm = $value;
      $today = $now->format('Y-m-d');
      $candidate = new \DateTimeImmutable($today . 'T' . $hm . ':00');
      if ($candidate <= $now) $candidate = $candidate->modify('+1 day');
      return $candidate->format(DATE_ATOM);
    }

    // fallback: 60s
    return $now->modify('+60 seconds')->format(DATE_ATOM);
  }
}
