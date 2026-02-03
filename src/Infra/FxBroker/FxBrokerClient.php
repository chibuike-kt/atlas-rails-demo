<?php
declare(strict_types=1);

namespace Infra\FxBroker;

use RuntimeException;

final class FxBrokerClient {
  public function ngnToUsd(int $ngnMinor): array {
    // Demo rate: 1 USD = 1500 NGN (rough demo)
    $rate = 1500; // NGN per USD
    $usdCents = intdiv($ngnMinor, $rate); // treat "minor" loosely for demo
    if ($usdCents <= 0) throw new RuntimeException("amount too small for fx");
    return ['rate' => $rate, 'usd_minor' => $usdCents, 'ref' => 'fx_ngn_usd_' . bin2hex(random_bytes(6))];
  }

  public function usdToUsdt(int $usdMinor): array {
    // Demo: 1:1
    if ($usdMinor <= 0) throw new RuntimeException("usd too small for usdt");
    return ['rate' => 1, 'usdt_minor' => $usdMinor, 'ref' => 'fx_usd_usdt_' . bin2hex(random_bytes(6))];
  }
}
