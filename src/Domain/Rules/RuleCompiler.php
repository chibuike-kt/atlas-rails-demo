<?php
declare(strict_types=1);

namespace Domain\Rules;

final class RuleCompiler {

  /**
   * Very deterministic demo parser.
   * Transcript example:
   *   "Every day at 09:00 move 5000 NGN into savings"
   * Also supports:
   *   "Every 60 seconds move 1000 NGN into savings"
   */
  public function proposeFromTranscript(string $transcript): array {
    $t = trim($transcript);

    // Amount
    $amount = 0;
    if (preg_match('/\bmove\s+([0-9]+)\s*(NGN|ngn)\b/', $t, $m)) {
      $amount = (int)$m[1];
    }

    // Schedule: daily_at or interval_seconds
    $schedule = null;
    if (preg_match('/\bat\s+([0-2][0-9]:[0-5][0-9])\b/', $t, $m)) {
      $schedule = ['type' => 'daily_at', 'value' => $m[1]];
    } elseif (preg_match('/\bevery\s+([0-9]+)\s*seconds?\b/i', $t, $m)) {
      $schedule = ['type' => 'interval_seconds', 'value' => (string)((int)$m[1])];
    }

    $toSavings = (bool)preg_match('/\binto\s+savings\b/i', $t);

    $actions = [];
    if ($amount > 0 && $toSavings) {
      $actions[] = [
        'type' => 'savings_deposit',
        'amount_minor' => $amount * 100, // kobo
        'currency' => 'NGN'
      ];
    }

    return [
      'schedule' => $schedule,
      'actions' => $actions,
      'notes' => [
        'parser' => 'deterministic_demo_parser',
        'warning' => 'voice simulated; transcript is treated as input'
      ]
    ];
  }

  public function compileGraph(array $confirmedRule): array {
    // Simple DAG: schedule -> action nodes
    $nodes = [];
    $edges = [];

    $nodes[] = ['id' => 'n1', 'type' => 'schedule', 'data' => $confirmedRule['schedule'] ?? null];

    $i = 2;
    foreach (($confirmedRule['actions'] ?? []) as $act) {
      $nid = 'n' . $i++;
      $nodes[] = ['id' => $nid, 'type' => 'action', 'data' => $act];
      $edges[] = ['from' => 'n1', 'to' => $nid];
    }

    return ['nodes' => $nodes, 'edges' => $edges];
  }
}
