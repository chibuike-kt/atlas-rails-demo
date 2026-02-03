<?php
declare(strict_types=1);

namespace Infra\BillAggregator;

final class BillAggregatorClient {
  public function settle(string $biller, string $account, int $amountMinor, string $currency): array {
    return [
      'agg_ref' => 'bill_' . bin2hex(random_bytes(6)),
      'status' => 'settled',
      'biller' => $biller,
      'account' => $account
    ];
  }
}
