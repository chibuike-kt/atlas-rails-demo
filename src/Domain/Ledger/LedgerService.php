<?php
declare(strict_types=1);

namespace Domain\Ledger;

use App\Db\Db;
use App\Support\Util;
use PDO;
use RuntimeException;

final class LedgerService {

  /**
   * lines: [
   *   ['account_id' => '...', 'direction' => 'debit|credit', 'amount_minor' => 123, 'currency' => 'NGN', 'memo' => '...'],
   * ]
   */
  public function postEntry(string $correlationId, string $reference, array $lines): string {
    if (count($lines) < 2) throw new RuntimeException("ledger requires >= 2 lines");

    // Check per-currency balancing: sum(debit) == sum(credit)
    $sum = [];
    foreach ($lines as $ln) {
      $ccy = (string)$ln['currency'];
      $amt = (int)$ln['amount_minor'];
      if ($amt <= 0) throw new RuntimeException("amount_minor must be > 0");
      $dir = (string)$ln['direction'];
      if (!in_array($dir, ['debit','credit'], true)) throw new RuntimeException("bad direction");
      $sum[$ccy] ??= ['debit' => 0, 'credit' => 0];
      $sum[$ccy][$dir] += $amt;
    }
    foreach ($sum as $ccy => $v) {
      if (($v['debit'] ?? 0) !== ($v['credit'] ?? 0)) {
        throw new RuntimeException("unbalanced entry for $ccy");
      }
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();

    $entryId = Util::uuid();
    $stmt = $pdo->prepare("INSERT INTO ledger_entries (id, correlation_id, reference, created_at) VALUES (?,?,?,?)");
    $stmt->execute([$entryId, $correlationId, $reference, Util::now()]);

    $ins = $pdo->prepare(
      "INSERT INTO ledger_lines (id, entry_id, account_id, direction, amount_minor, currency, memo, created_at)
       VALUES (?,?,?,?,?,?,?,?)"
    );

    foreach ($lines as $ln) {
      $ins->execute([
        Util::uuid(),
        $entryId,
        (string)$ln['account_id'],
        (string)$ln['direction'],
        (int)$ln['amount_minor'],
        (string)$ln['currency'],
        (string)($ln['memo'] ?? ''),
        Util::now()
      ]);
    }

    $pdo->commit();
    return $entryId;
  }

  /** returns [account_id => net_minor], where credits are + and debits are - */
  public function balancesForUser(string $userId): array {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare(
      "SELECT la.id as account_id,
              SUM(CASE WHEN ll.direction='credit' THEN ll.amount_minor ELSE -ll.amount_minor END) as net_minor,
              la.currency as currency,
              la.type as type,
              la.name as name
       FROM ledger_accounts la
       LEFT JOIN ledger_lines ll ON ll.account_id = la.id
       WHERE la.user_id = ?
       GROUP BY la.id, la.currency, la.type, la.name"
    );
    $stmt->execute([$userId]);
    $out = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out[] = [
        'account_id' => $r['account_id'],
        'name' => $r['name'],
        'type' => $r['type'],
        'currency' => $r['currency'],
        'net_minor' => (int)($r['net_minor'] ?? 0),
      ];
    }
    return $out;
  }

  public function entriesByReference(string $reference): array {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT * FROM ledger_entries WHERE reference = ? ORDER BY created_at ASC");
    $stmt->execute([$reference]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($entries as &$e) {
      $l = $pdo->prepare("SELECT * FROM ledger_lines WHERE entry_id = ? ORDER BY created_at ASC");
      $l->execute([$e['id']]);
      $e['lines'] = $l->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    return $entries;
  }

  public function getSystemAccountId(string $type, string $currency): string {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT id FROM ledger_accounts WHERE user_id IS NULL AND type = ? AND currency = ? LIMIT 1");
    $stmt->execute([$type, $currency]);
    $id = $stmt->fetchColumn();
    if (!$id) throw new RuntimeException("missing system account $type/$currency (did you seed?)");
    return (string)$id;
  }

  public function ensureUserAccounts(string $userId): void {
    $pdo = Db::pdo();

    $needed = [
      ['user_fiat','NGN','User Fiat NGN'],
      ['user_savings','NGN','User Savings NGN'],
      ['user_fiat','USD','User Fiat USD'],
      ['user_fiat','USDT','User Fiat USDT (internal)'],
    ];

    foreach ($needed as [$type,$ccy,$name]) {
      $stmt = $pdo->prepare("SELECT id FROM ledger_accounts WHERE user_id = ? AND type = ? AND currency = ? LIMIT 1");
      $stmt->execute([$userId, $type, $ccy]);
      $id = $stmt->fetchColumn();
      if ($id) continue;

      $ins = $pdo->prepare(
        "INSERT INTO ledger_accounts (id, user_id, type, currency, name, created_at)
         VALUES (?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([Util::uuid(), $userId, $type, $ccy, $name, Util::now()]);
    }
  }

  public function getUserAccountId(string $userId, string $type, string $currency): string {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT id FROM ledger_accounts WHERE user_id = ? AND type = ? AND currency = ? LIMIT 1");
    $stmt->execute([$userId, $type, $currency]);
    $id = $stmt->fetchColumn();
    if (!$id) throw new RuntimeException("missing user account $type/$currency (call ensureUserAccounts)");
    return (string)$id;
  }
}
