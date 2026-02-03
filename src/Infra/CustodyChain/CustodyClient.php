<?php
declare(strict_types=1);

namespace Infra\CustodyChain;

final class CustodyClient {
  public function withdrawUsdt(string $toAddress, int $amountMinor, string $network): array {
    return [
      'network' => $network,
      'tx_hash' => '0x' . bin2hex(random_bytes(16)),
      'status' => 'broadcast',
      'to' => $toAddress,
      'amount_minor' => $amountMinor
    ];
  }
}
