<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:storage/demo.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function uuid(): string {
  return bin2hex(random_bytes(16));
}
function now(): string {
  return (new DateTimeImmutable('now'))->format(DATE_ATOM);
}

$systemAccounts = [
  ['system_clearing','NGN','System Clearing NGN'],
  ['system_revenue','NGN','System Revenue NGN'],
  ['fx_inventory','USD','FX Inventory USD'],
  ['custody_hot','USDT','Custody Hot Wallet USDT'],
];

foreach ($systemAccounts as [$type,$ccy,$name]) {
  $id = uuid();
  $stmt = $pdo->prepare("INSERT INTO ledger_accounts (id,user_id,type,currency,name,created_at) VALUES (?,?,?,?,?,?)");
  $stmt->execute([$id,null,$type,$ccy,$name,now()]);
}

echo "Seeded system accounts.\n";
