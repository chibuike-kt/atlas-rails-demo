<?php
declare(strict_types=1);

namespace App\Queue;

use App\Logging\AuditLogger;
use App\Support\Util;
use Domain\Ledger\LedgerService;
use Domain\Shared\TransferRepo;
use Infra\OpenBanking\OpenBankingClient;
use Infra\BillAggregator\BillAggregatorClient;
use Infra\FxBroker\FxBrokerClient;
use Infra\CustodyChain\CustodyClient;
use Domain\Rules\RuleCompiler;
use App\Db\Db;
use PDO;
use RuntimeException;

final class Worker {

  public function __construct(
    private readonly JobRepository $jobs = new JobRepository(),
    private readonly AuditLogger $audit = new AuditLogger(),
    private readonly LedgerService $ledger = new LedgerService(),
    private readonly TransferRepo $transfers = new TransferRepo(),
    private readonly OpenBankingClient $ob = new OpenBankingClient(),
    private readonly BillAggregatorClient $bills = new BillAggregatorClient(),
    private readonly FxBrokerClient $fx = new FxBrokerClient(),
    private readonly CustodyClient $custody = new CustodyClient(),
  ) {}

  public function runForever(int $sleepMs = 250): void {
    while (true) {
      $job = $this->jobs->fetchAndLockNext();
      if (!$job) {
        usleep($sleepMs * 1000);
        continue;
      }

      $jobId = (string)$job['id'];
      $type = (string)$job['type'];
      $corr = (string)$job['correlation_id'];
      $attempt = (int)$job['attempt'];
      $maxAttempts = (int)$job['max_attempts'];
      $payload = Util::jsonDecode((string)$job['payload_json']);

      try {
        $this->audit->log($corr, 'system', 'job.started', [
          'job_id' => $jobId,
          'type' => $type,
          'attempt' => $attempt,
        ]);

        // failpoint injection for demo
        if (Failpoints::shouldFail($type)) {
          throw new RuntimeException("transient_injected_failure for $type");
        }

        $this->handle($type, $corr, $payload);

        $this->jobs->markSucceeded($jobId);
        $this->audit->log($corr, 'system', 'job.succeeded', [
          'job_id' => $jobId,
          'type' => $type
        ]);
      } catch (\Throwable $e) {
        $this->audit->log($corr, 'system', 'job.failed', [
          'job_id' => $jobId,
          'type' => $type,
          'error' => $e->getMessage()
        ]);
        $this->jobs->markFailedAndRetry($jobId, $attempt, $maxAttempts, $e->getMessage());
      }
    }
  }

  private function handle(string $type, string $corr, array $p): void {
    return match ($type) {
      'fiat_fund' => $this->handleFiatFund($corr, $p),
      'p2p_transfer' => $this->handleP2P($corr, $p),
      'savings_deposit' => $this->handleSavingsDeposit($corr, $p),
      'bill_settlement' => $this->handleBillSettlement($corr, $p),
      'fx_convert' => $this->handleFxConvert($corr, $p),
      'custody_withdraw' => $this->handleCustodyWithdraw($corr, $p),
      'rule_execute' => $this->handleRuleExecute($corr, $p),
      default => throw new RuntimeException("unknown job type: $type"),
    };
  }

  private function handleFiatFund(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $bankLinkRef = (string)$p['bank_external_ref'];
    $amount = (int)$p['amount_minor'];
    $reference = (string)$p['reference']; // fund:...

    $this->audit->log($corr, "user:$userId", 'fiat_fund.begin', $p);
    $this->transfers->updateStatus($reference, 'processing');

    // 1) debit bank
    $res = $this->ob->debitBank($bankLinkRef, $amount, 'NGN');

    // 2) ledger: system clearing -> user fiat
    $this->ledger->ensureUserAccounts($userId);
    $userFiat = $this->ledger->getUserAccountId($userId, 'user_fiat', 'NGN');
    $clearing = $this->ledger->getSystemAccountId('system_clearing', 'NGN');

    $this->ledger->postEntry($corr, $reference, [
      ['account_id' => $clearing, 'direction' => 'debit', 'amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'bank debit clearing'],
      ['account_id' => $userFiat,  'direction' => 'credit','amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'fund user fiat balance'],
    ]);

    $this->transfers->updateStatus($reference, 'settled', ['bank' => $res]);
    $this->audit->log($corr, "user:$userId", 'fiat_fund.settled', ['bank' => $res, 'reference' => $reference]);
  }

  private function handleP2P(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $bankLinkRef = (string)$p['bank_external_ref'];
    $amount = (int)$p['amount_minor'];
    $reference = (string)$p['reference'];
    $toAccount = (string)$p['to_account'];

    $this->audit->log($corr, "user:$userId", 'p2p.begin', $p);
    $this->transfers->updateStatus($reference, 'processing');

    // bank-initiated credit outward
    $bankRes = $this->ob->creditBank($bankLinkRef, $amount, 'NGN');

    // ledger: user fiat -> system clearing (reduces user balance), then clearing -> revenue? (demo keeps in clearing)
    $this->ledger->ensureUserAccounts($userId);
    $userFiat = $this->ledger->getUserAccountId($userId, 'user_fiat', 'NGN');
    $clearing = $this->ledger->getSystemAccountId('system_clearing', 'NGN');

    $this->ledger->postEntry($corr, $reference, [
      ['account_id' => $userFiat, 'direction' => 'debit',  'amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'p2p send'],
      ['account_id' => $clearing, 'direction' => 'credit','amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'bank outbound clearing'],
    ]);

    $this->transfers->updateStatus($reference, 'settled', ['bank' => $bankRes, 'to_account' => $toAccount]);
    $this->audit->log($corr, "user:$userId", 'p2p.settled', ['bank' => $bankRes, 'reference' => $reference]);
  }

  private function handleSavingsDeposit(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $amount = (int)$p['amount_minor'];
    $reference = (string)$p['reference'];

    $this->audit->log($corr, "user:$userId", 'savings.begin', $p);
    $this->transfers->updateStatus($reference, 'processing');

    $this->ledger->ensureUserAccounts($userId);
    $fiat = $this->ledger->getUserAccountId($userId, 'user_fiat', 'NGN');
    $savings = $this->ledger->getUserAccountId($userId, 'user_savings', 'NGN');

    $this->ledger->postEntry($corr, $reference, [
      ['account_id' => $fiat,   'direction' => 'debit',  'amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'move to savings'],
      ['account_id' => $savings,'direction' => 'credit', 'amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'savings deposit'],
    ]);

    $this->transfers->updateStatus($reference, 'settled');
    $this->audit->log($corr, "user:$userId", 'savings.settled', ['reference' => $reference]);
  }

  private function handleBillSettlement(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $amount = (int)$p['amount_minor'];
    $reference = (string)$p['reference'];
    $biller = (string)$p['biller'];
    $account = (string)$p['account'];

    $this->audit->log($corr, "user:$userId", 'bill.begin', $p);
    $this->transfers->updateStatus($reference, 'processing');

    // call aggregator
    $res = $this->bills->settle($biller, $account, $amount, 'NGN');

    // ledger: user fiat -> clearing
    $this->ledger->ensureUserAccounts($userId);
    $fiat = $this->ledger->getUserAccountId($userId, 'user_fiat', 'NGN');
    $clearing = $this->ledger->getSystemAccountId('system_clearing', 'NGN');

    $this->ledger->postEntry($corr, $reference, [
      ['account_id' => $fiat, 'direction' => 'debit', 'amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'bill payment'],
      ['account_id' => $clearing, 'direction' => 'credit', 'amount_minor' => $amount, 'currency' => 'NGN', 'memo' => 'bill clearing'],
    ]);

    $this->transfers->updateStatus($reference, 'settled', ['agg' => $res]);
    $this->audit->log($corr, "user:$userId", 'bill.settled', ['agg' => $res, 'reference' => $reference]);
  }

  private function handleFxConvert(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $ngnAmount = (int)$p['ngn_amount_minor'];
    $reference = (string)$p['reference']; // fx:...

    $this->audit->log($corr, "user:$userId", 'fx.begin', $p);
    $this->transfers->updateStatus($reference, 'processing');

    $this->ledger->ensureUserAccounts($userId);

    $userNgn = $this->ledger->getUserAccountId($userId, 'user_fiat', 'NGN');
    $userUsd = $this->ledger->getUserAccountId($userId, 'user_fiat', 'USD');
    $userUsdt = $this->ledger->getUserAccountId($userId, 'user_fiat', 'USDT');

    $fxInventory = $this->ledger->getSystemAccountId('fx_inventory', 'USD');
    $clearingNgn = $this->ledger->getSystemAccountId('system_clearing', 'NGN');

    // Step 1: take NGN from user into clearing
    $this->ledger->postEntry($corr, $reference . ':ngn_leg', [
      ['account_id' => $userNgn, 'direction' => 'debit', 'amount_minor' => $ngnAmount, 'currency' => 'NGN', 'memo' => 'fx sell NGN'],
      ['account_id' => $clearingNgn, 'direction' => 'credit', 'amount_minor' => $ngnAmount, 'currency' => 'NGN', 'memo' => 'fx receive NGN'],
    ]);

    // Broker quote
    $q1 = $this->fx->ngnToUsd($ngnAmount);
    $usdMinor = (int)$q1['usd_minor'];

    // Step 2: give USD to user from fx inventory
    $this->ledger->postEntry($corr, $reference . ':usd_leg', [
      ['account_id' => $fxInventory, 'direction' => 'debit', 'amount_minor' => $usdMinor, 'currency' => 'USD', 'memo' => 'fx inventory out'],
      ['account_id' => $userUsd,     'direction' => 'credit','amount_minor' => $usdMinor, 'currency' => 'USD', 'memo' => 'user receives USD'],
    ]);

    // Step 3: USD -> USDT (1:1 demo)
    $q2 = $this->fx->usdToUsdt($usdMinor);
    $usdtMinor = (int)$q2['usdt_minor'];

    $this->ledger->postEntry($corr, $reference . ':usdt_leg', [
      ['account_id' => $userUsd,  'direction' => 'debit',  'amount_minor' => $usdMinor, 'currency' => 'USD',  'memo' => 'swap USD to USDT'],
      ['account_id' => $userUsdt, 'direction' => 'credit', 'amount_minor' => $usdtMinor,'currency' => 'USDT', 'memo' => 'user receives USDT'],
    ]);

    $this->transfers->updateStatus($reference, 'settled', ['q1' => $q1, 'q2' => $q2]);
    $this->audit->log($corr, "user:$userId", 'fx.settled', ['reference' => $reference, 'q1' => $q1, 'q2' => $q2]);
  }

  private function handleCustodyWithdraw(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $amount = (int)$p['amount_minor'];
    $reference = (string)$p['reference']; // custody:...
    $to = (string)$p['to_address'];
    $network = (string)$p['network'];

    $this->audit->log($corr, "user:$userId", 'custody.begin', $p);
    $this->transfers->updateStatus($reference, 'processing');

    $this->ledger->ensureUserAccounts($userId);
    $userUsdt = $this->ledger->getUserAccountId($userId, 'user_fiat', 'USDT');
    $hot = $this->ledger->getSystemAccountId('custody_hot', 'USDT');

    // ledger: remove USDT from user balance into hot wallet (custody outflow is external)
    $this->ledger->postEntry($corr, $reference . ':reserve', [
      ['account_id' => $userUsdt, 'direction' => 'debit', 'amount_minor' => $amount, 'currency' => 'USDT', 'memo' => 'reserve withdrawal'],
      ['account_id' => $hot,      'direction' => 'credit','amount_minor' => $amount, 'currency' => 'USDT', 'memo' => 'custody hot allocation'],
    ]);

    // custody withdraw
    $res = $this->custody->withdrawUsdt($to, $amount, $network);

    $this->transfers->updateStatus($reference, 'settled', ['chain' => $res]);
    $this->audit->log($corr, "user:$userId", 'custody.settled', ['reference' => $reference, 'chain' => $res]);
  }

  private function handleRuleExecute(string $corr, array $p): void {
    $userId = (string)$p['user_id'];
    $ruleId = (string)$p['rule_id'];

    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT * FROM rules WHERE id=? LIMIT 1");
    $stmt->execute([$ruleId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException("rule not found: $ruleId");
    if (($r['status'] ?? '') !== 'confirmed') throw new RuntimeException("rule not confirmed: $ruleId");

    $confirmed = Util::jsonDecode((string)$r['confirmed_json']);
    $this->audit->log($corr, "system", 'rule.execute.begin', ['rule_id' => $ruleId, 'user_id' => $userId]);

    // Execute actions as separate jobs for reliability
    $jr = new JobRepository();
    foreach (($confirmed['actions'] ?? []) as $act) {
      if (($act['type'] ?? '') === 'savings_deposit') {
        $ref = 'savings:' . bin2hex(random_bytes(8));
        (new \Domain\Shared\TransferRepo())->create($userId, 'savings', $ref, (int)$act['amount_minor'], (string)$act['currency'], [
          'from_rule' => $ruleId
        ], 'created');

        $jr->enqueue($corr, 'savings_deposit', [
          'user_id' => $userId,
          'amount_minor' => (int)$act['amount_minor'],
          'reference' => $ref
        ], 0, 8);
      }
    }

    $this->audit->log($corr, "system", 'rule.execute.enqueued_actions', ['rule_id' => $ruleId]);
  }
}
