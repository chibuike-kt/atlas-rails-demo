<?php
declare(strict_types=1);

namespace App\Queue;

final class Failpoints {
  /**
   * FAILPOINTS env format:
   *   "fiat_fund:30,p2p_transfer:20,fx_convert:50,bill_settlement:40,custody_withdraw:25"
   * Means % probability a job attempt throws a transient error.
   */
  public static function shouldFail(string $jobType): bool {
    $raw = getenv('FAILPOINTS') ?: '';
    if ($raw === '') return false;

    $pairs = array_filter(array_map('trim', explode(',', $raw)));
    $map = [];
    foreach ($pairs as $p) {
      $kv = array_map('trim', explode(':', $p, 2));
      if (count($kv) !== 2) continue;
      $map[$kv[0]] = (int)$kv[1];
    }
    $pct = $map[$jobType] ?? 0;
    if ($pct <= 0) return false;
    if ($pct >= 100) return true;

    $r = random_int(1, 100);
    return $r <= $pct;
  }
}
