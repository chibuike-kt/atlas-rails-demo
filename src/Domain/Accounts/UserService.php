<?php
declare(strict_types=1);

namespace Domain\Accounts;

use App\Db\Db;
use App\Support\Util;
use Domain\Ledger\LedgerService;

final class UserService {
  public function createUser(string $email): array {
    $pdo = Db::pdo();
    $id = Util::uuid();
    $stmt = $pdo->prepare("INSERT INTO users (id,email,created_at) VALUES (?,?,?)");
    $stmt->execute([$id, $email, Util::now()]);

    // ensure ledger accounts
    $ledger = new LedgerService();
    $ledger->ensureUserAccounts($id);

    return ['id' => $id, 'email' => $email];
  }
}
