<?php
declare(strict_types=1);

namespace Domain\Shared;

use App\Db\Db;
use App\Support\Util;
use PDO;

final class TransferRepo {

  public function create(string $userId, string $kind, string $reference, int $amountMinor, string $currency, array $meta, string $status='created'): string {
    $pdo = Db::pdo();
    $id = Util::uuid();
    $now = Util::now();

    $stmt = $pdo->prepare(
      "INSERT INTO transfers (id,user_id,kind,status,reference,amount_minor,currency,meta_json,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
      $id,$userId,$kind,$status,$reference,$amountMinor,$currency,Util::jsonEncode($meta),$now,$now
    ]);
    return $id;
  }

  public function updateStatus(string $reference, string $status, array $metaPatch = []): void {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT meta_json FROM transfers WHERE reference=? LIMIT 1");
    $stmt->execute([$reference]);
    $raw = (string)($stmt->fetchColumn() ?: '{}');
    $meta = Util::jsonDecode($raw);
    foreach ($metaPatch as $k=>$v) $meta[$k]=$v;

    $upd = $pdo->prepare("UPDATE transfers SET status=?, meta_json=?, updated_at=? WHERE reference=?");
    $upd->execute([$status, Util::jsonEncode($meta), Util::now(), $reference]);
  }

  public function getByReference(string $reference): ?array {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT * FROM transfers WHERE reference=? LIMIT 1");
    $stmt->execute([$reference]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['meta'] = Util::jsonDecode((string)$r['meta_json']);
    return $r;
  }
}
