<?php
declare(strict_types=1);

namespace Infra\OpenBanking;

use RuntimeException;

final class OpenBankingClient {

  public function startLink(string $userId): array {
    $linkRef = bin2hex(random_bytes(8));
    return [
      'provider' => 'mock_openbanking',
      'external_ref' => 'obl_' . $linkRef,
      'link_url' => 'https://mock.openbanking/link/' . $linkRef
    ];
  }

  public function completeLink(string $externalRef): array {
    // mock: returns bank details
    return [
      'bank_name' => 'Mock Bank',
      'account_last4' => (string)random_int(1000, 9999),
      'external_ref' => $externalRef
    ];
  }

  public function debitBank(string $externalRef, int $amountMinor, string $currency): array {
    if ($currency !== 'NGN') throw new RuntimeException("bank debit supports NGN only in demo");
    return ['bank_txn' => 'bank_debit_' . bin2hex(random_bytes(6)), 'status' => 'debited'];
  }

  public function creditBank(string $externalRef, int $amountMinor, string $currency): array {
    if ($currency !== 'NGN') throw new RuntimeException("bank credit supports NGN only in demo");
    return ['bank_txn' => 'bank_credit_' . bin2hex(random_bytes(6)), 'status' => 'credited'];
  }
}
