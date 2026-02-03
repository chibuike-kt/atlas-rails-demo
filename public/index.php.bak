<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Http\Router;
use App\Http\Request;
use App\Http\JsonResponse;
use App\Support\Util;
use App\Logging\AuditLogger;
use App\Idempotency\IdempotencyService;
use Domain\Accounts\UserService;
use Domain\Ledger\LedgerService;
use Domain\Shared\TransferRepo;
use App\Queue\JobRepository;
use Infra\OpenBanking\OpenBankingClient;
use Domain\Rules\RuleCompiler;
use App\Db\Db;
use PDO;

$router = new Router();
$audit = new AuditLogger();
$idem = new IdempotencyService();
$ledger = new LedgerService();
$transfers = new TransferRepo();
$jobs = new JobRepository();
$ob = new OpenBankingClient();
$ruleCompiler = new RuleCompiler();

function ctx(): array {
  $corr = Request::header('X-Correlation-Id');
  if (!$corr) $corr = 'corr_' . bin2hex(random_bytes(8));
  $actor = Request::header('X-Actor') ?: 'system';
  return [$corr, $actor];
}

function requireIdemKey(): string {
  $k = Request::header('Idempotency-Key');
  if (!$k) {
    JsonResponse::send(400, ['error' => 'missing_idempotency_key']);
    exit;
  }
  return $k;
}

function withIdempotency(string $route, callable $fn): void {
  global $idem;
  [$corr, $actor] = ctx();
  $idemKey = requireIdemKey();
  $raw = Request::raw();
  $hash = Util::hashRequest(Request::method(), Request::path(), $raw);

  $start = $idem->start($idemKey, $route, $hash);
  if ($start['status'] === 'hash_mismatch') {
    JsonResponse::send(422, ['error' => 'idempotency_hash_mismatch']);
    return;
  }
  if ($start['status'] === 'replay') {
    $row = $start['row'];
    $code = (int)($row['response_code'] ?? 200);
    $body = Util::jsonDecode((string)($row['response_body'] ?? '{}'));
    JsonResponse::send($code, $body, ['X-Correlation-Id' => $corr, 'X-Idempotency-Replay' => '1']);
    return;
  }
  if ($start['status'] === 'in_progress') {
    JsonResponse::send(409, ['error' => 'idempotency_in_progress'], ['Retry-After' => '2', 'X-Correlation-Id' => $corr]);
    return;
  }

  // Execute
  try {
    $result = $fn($corr, $actor);
    $code = $result['_code'] ?? 200;
    unset($result['_code']);
    $idem->complete($idemKey, $route, (int)$code, $result);
    JsonResponse::send((int)$code, $result, ['X-Correlation-Id' => $corr]);
  } catch (\Throwable $e) {
    $body = ['error' => 'internal_error', 'message' => $e->getMessage()];
    $idem->fail($idemKey, $route, 500, $body);
    JsonResponse::send(500, $body, ['X-Correlation-Id' => $corr]);
  }
}

$router->add('GET', '/', function () {
  header('Content-Type: text/html; charset=utf-8');
  echo "<h1>Atlas Rails Demo</h1><p>Systems behavior demo. Use curl. See docs/demo.md</p>";
});

$router->add('GET', '/health', function () {
  [$corr] = ctx();
  JsonResponse::send(200, ['ok' => true, 'ts' => Util::now()], ['X-Correlation-Id' => $corr]);
});

$router->add('POST', '/users', function () {
  withIdempotency('POST:/users', function ($corr, $actor) {
    global $audit;
    $in = Request::json();
    $email = (string)($in['email'] ?? '');
    if ($email === '') return ['_code' => 400, 'error' => 'missing_email'];

    $u = (new UserService())->createUser($email);
    $audit->log($corr, $actor, 'user.created', $u);
    return ['user' => $u];
  });
});

$router->add('POST', '/bank_links/start', function () {
  withIdempotency('POST:/bank_links/start', function ($corr, $actor) {
    global $audit, $ob;
    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    if ($userId === '') return ['_code' => 400, 'error' => 'missing_user_id'];

    $res = $ob->startLink($userId);

    $pdo = Db::pdo();
    $id = Util::uuid();
    $stmt = $pdo->prepare(
      "INSERT INTO bank_links (id,user_id,provider,status,external_ref,created_at) VALUES (?,?,?,?,?,?)"
    );
    $stmt->execute([$id, $userId, $res['provider'], 'pending', $res['external_ref'], Util::now()]);

    $audit->log($corr, $actor, 'bank_link.started', ['user_id' => $userId, 'bank_link_id' => $id, 'external_ref' => $res['external_ref']]);
    return ['bank_link_id' => $id, 'link_url' => $res['link_url'], 'external_ref' => $res['external_ref']];
  });
});

$router->add('POST', '/bank_links/complete', function () {
  withIdempotency('POST:/bank_links/complete', function ($corr, $actor) {
    global $audit, $ob;
    $in = Request::json();
    $externalRef = (string)($in['external_ref'] ?? '');
    if ($externalRef === '') return ['_code' => 400, 'error' => 'missing_external_ref'];

    $details = $ob->completeLink($externalRef);

    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT id,user_id FROM bank_links WHERE external_ref=? LIMIT 1");
    $stmt->execute([$externalRef]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['_code' => 404, 'error' => 'link_not_found'];

    $upd = $pdo->prepare(
      "UPDATE bank_links SET status='linked', bank_name=?, account_last4=?, linked_at=? WHERE id=?"
    );
    $upd->execute([$details['bank_name'], $details['account_last4'], Util::now(), $row['id']]);

    $audit->log($corr, $actor, 'bank_link.completed', ['bank_link_id' => $row['id'], 'user_id' => $row['user_id']]);
    return ['bank_link_id' => $row['id'], 'status' => 'linked', 'bank_name' => $details['bank_name'], 'account_last4' => $details['account_last4']];
  });
});

$router->add('GET', '/bank_links', function () {
  [$corr] = ctx();
  $userId = $_GET['user_id'] ?? '';
  if ($userId === '') { JsonResponse::send(400, ['error' => 'missing_user_id'], ['X-Correlation-Id' => $corr]); return; }
  $pdo = Db::pdo();
  $stmt = $pdo->prepare("SELECT * FROM bank_links WHERE user_id=? ORDER BY created_at ASC");
  $stmt->execute([$userId]);
  JsonResponse::send(200, ['bank_links' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], ['X-Correlation-Id' => $corr]);
});

$router->add('POST', '/fiat/fund', function () {
  withIdempotency('POST:/fiat/fund', function ($corr, $actor) {
    global $audit, $transfers, $jobs;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $amountMinor = (int)($in['amount_minor'] ?? 0);
    $bankExternalRef = (string)($in['bank_external_ref'] ?? '');

    if ($userId === '' || $amountMinor <= 0 || $bankExternalRef === '') {
      return ['_code' => 400, 'error' => 'missing_user_id_or_amount_or_bank_ref'];
    }

    $ref = 'fund:' . bin2hex(random_bytes(8));
    $transfers->create($userId, 'fund', $ref, $amountMinor, 'NGN', ['bank_external_ref' => $bankExternalRef], 'created');

    $jobs->enqueue($corr, 'fiat_fund', [
      'user_id' => $userId,
      'amount_minor' => $amountMinor,
      'bank_external_ref' => $bankExternalRef,
      'reference' => $ref
    ], 0, 8);

    $audit->log($corr, $actor, 'fiat_fund.enqueued', ['reference' => $ref, 'amount_minor' => $amountMinor]);
    return ['reference' => $ref, 'status' => 'queued'];
  });
});

$router->add('GET', '/balances', function () {
  [$corr] = ctx();
  $userId = $_GET['user_id'] ?? '';
  if ($userId === '') { JsonResponse::send(400, ['error' => 'missing_user_id'], ['X-Correlation-Id' => $corr]); return; }
  $balances = (new LedgerService())->balancesForUser((string)$userId);
  JsonResponse::send(200, ['balances' => $balances], ['X-Correlation-Id' => $corr]);
});

$router->add('POST', '/voice/rules/propose', function () {
  withIdempotency('POST:/voice/rules/propose', function ($corr, $actor) {
    global $audit, $ruleCompiler;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $transcript = (string)($in['transcript'] ?? '');
    if ($userId === '' || $transcript === '') return ['_code' => 400, 'error' => 'missing_user_id_or_transcript'];

    $proposal = $ruleCompiler->proposeFromTranscript($transcript);

    $pdo = Db::pdo();
    $ruleId = Util::uuid();
    $stmt = $pdo->prepare(
      "INSERT INTO rules (id,user_id,status,transcript,proposed_json,created_at)
       VALUES (?,?,?,?,?,?)"
    );
    $stmt->execute([$ruleId, $userId, 'proposed', $transcript, Util::jsonEncode($proposal), Util::now()]);

    $audit->log($corr, $actor, 'rule.proposed', ['rule_id' => $ruleId, 'proposal' => $proposal]);
    return ['rule_id' => $ruleId, 'status' => 'proposed', 'proposal' => $proposal];
  });
});

$router->add('POST', '/voice/rules/confirm', function () {
  withIdempotency('POST:/voice/rules/confirm', function ($corr, $actor) {
    global $audit, $ruleCompiler;

    $in = Request::json();
    $ruleId = (string)($in['rule_id'] ?? '');
    $confirm = (bool)($in['confirm'] ?? false);
    if ($ruleId === '' || !$confirm) return ['_code' => 400, 'error' => 'missing_rule_id_or_confirm_false'];

    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT * FROM rules WHERE id=? LIMIT 1");
    $stmt->execute([$ruleId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['_code' => 404, 'error' => 'rule_not_found'];
    if (($r['status'] ?? '') !== 'proposed') return ['_code' => 409, 'error' => 'rule_not_proposed'];

    $proposal = Util::jsonDecode((string)$r['proposed_json']);
    $graph = $ruleCompiler->compileGraph($proposal);

    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE rules SET status='confirmed', confirmed_json=?, confirmed_at=? WHERE id=?");
    $upd->execute([Util::jsonEncode($proposal), Util::now(), $ruleId]);

    $gid = Util::uuid();
    $ins = $pdo->prepare("INSERT INTO rule_graphs (id,rule_id,graph_json,created_at) VALUES (?,?,?,?)");
    $ins->execute([$gid, $ruleId, Util::jsonEncode($graph), Util::now()]);
    $pdo->commit();

    $audit->log($corr, $actor, 'rule.confirmed', ['rule_id' => $ruleId, 'graph_id' => $gid, 'graph' => $graph]);
    return ['rule_id' => $ruleId, 'status' => 'confirmed', 'graph' => $graph];
  });
});

$router->add('GET', '/rules/graph', function () {
  [$corr] = ctx();
  $ruleId = $_GET['rule_id'] ?? '';
  if ($ruleId === '') { JsonResponse::send(400, ['error' => 'missing_rule_id'], ['X-Correlation-Id' => $corr]); return; }
  $pdo = Db::pdo();
  $stmt = $pdo->prepare("SELECT * FROM rule_graphs WHERE rule_id=? ORDER BY created_at DESC LIMIT 1");
  $stmt->execute([$ruleId]);
  $g = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$g) { JsonResponse::send(404, ['error' => 'graph_not_found'], ['X-Correlation-Id' => $corr]); return; }
  JsonResponse::send(200, ['rule_id' => $ruleId, 'graph' => Util::jsonDecode((string)$g['graph_json'])], ['X-Correlation-Id' => $corr]);
});

$router->add('POST', '/schedules', function () {
  withIdempotency('POST:/schedules', function ($corr, $actor) {
    global $audit;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $ruleId = (string)($in['rule_id'] ?? '');
    $scheduleType = (string)($in['schedule_type'] ?? '');
    $scheduleValue = (string)($in['schedule_value'] ?? '');

    if ($userId === '' || $ruleId === '' || $scheduleType === '' || $scheduleValue === '') {
      return ['_code' => 400, 'error' => 'missing_fields'];
    }

    $next = (new \App\Scheduler\SchedulerService());
    // reuse its compute by calling tick-style logic? keep simple: set next_run_at = now
    $nextRunAt = Util::now();

    $pdo = Db::pdo();
    $id = Util::uuid();
    $stmt = $pdo->prepare(
      "INSERT INTO schedules (id,user_id,rule_id,schedule_type,schedule_value,next_run_at,status,created_at,updated_at)
       VALUES (?,?,?,?,?,?, 'active', ?, ?)"
    );
    $stmt->execute([$id,$userId,$ruleId,$scheduleType,$scheduleValue,$nextRunAt,Util::now(),Util::now()]);

    $audit->log($corr, $actor, 'schedule.created', ['schedule_id' => $id, 'rule_id' => $ruleId, 'type' => $scheduleType, 'value' => $scheduleValue]);
    return ['schedule_id' => $id, 'next_run_at' => $nextRunAt, 'status' => 'active'];
  });
});

$router->add('POST', '/transfers/p2p', function () {
  withIdempotency('POST:/transfers/p2p', function ($corr, $actor) {
    global $audit, $transfers, $jobs;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $amount = (int)($in['amount_minor'] ?? 0);
    $bankExternalRef = (string)($in['bank_external_ref'] ?? '');
    $toAccount = (string)($in['to_account'] ?? '');

    if ($userId === '' || $amount <= 0 || $bankExternalRef === '' || $toAccount === '') {
      return ['_code' => 400, 'error' => 'missing_fields'];
    }

    $ref = 'p2p:' . bin2hex(random_bytes(8));
    $transfers->create($userId, 'p2p', $ref, $amount, 'NGN', ['to_account' => $toAccount, 'bank_external_ref' => $bankExternalRef], 'created');

    $jobs->enqueue($corr, 'p2p_transfer', [
      'user_id' => $userId,
      'amount_minor' => $amount,
      'bank_external_ref' => $bankExternalRef,
      'to_account' => $toAccount,
      'reference' => $ref
    ], 0, 8);

    $audit->log($corr, $actor, 'p2p.enqueued', ['reference' => $ref, 'amount_minor' => $amount]);
    return ['reference' => $ref, 'status' => 'queued'];
  });
});

$router->add('POST', '/savings/deposit', function () {
  withIdempotency('POST:/savings/deposit', function ($corr, $actor) {
    global $audit, $transfers, $jobs;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $amount = (int)($in['amount_minor'] ?? 0);
    if ($userId === '' || $amount <= 0) return ['_code' => 400, 'error' => 'missing_fields'];

    $ref = 'savings:' . bin2hex(random_bytes(8));
    $transfers->create($userId, 'savings', $ref, $amount, 'NGN', [], 'created');

    $jobs->enqueue($corr, 'savings_deposit', [
      'user_id' => $userId,
      'amount_minor' => $amount,
      'reference' => $ref
    ], 0, 8);

    $audit->log($corr, $actor, 'savings.enqueued', ['reference' => $ref]);
    return ['reference' => $ref, 'status' => 'queued'];
  });
});

$router->add('POST', '/bills/settle', function () {
  withIdempotency('POST:/bills/settle', function ($corr, $actor) {
    global $audit, $transfers, $jobs;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $amount = (int)($in['amount_minor'] ?? 0);
    $biller = (string)($in['biller'] ?? '');
    $account = (string)($in['account'] ?? '');

    if ($userId === '' || $amount <= 0 || $biller === '' || $account === '') return ['_code' => 400, 'error' => 'missing_fields'];

    $ref = 'bill:' . bin2hex(random_bytes(8));
    $transfers->create($userId, 'bill', $ref, $amount, 'NGN', ['biller' => $biller, 'account' => $account], 'created');

    $jobs->enqueue($corr, 'bill_settlement', [
      'user_id' => $userId,
      'amount_minor' => $amount,
      'biller' => $biller,
      'account' => $account,
      'reference' => $ref
    ], 0, 8);

    $audit->log($corr, $actor, 'bill.enqueued', ['reference' => $ref]);
    return ['reference' => $ref, 'status' => 'queued'];
  });
});

$router->add('POST', '/fx/convert', function () {
  withIdempotency('POST:/fx/convert', function ($corr, $actor) {
    global $audit, $transfers, $jobs;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $ngnAmount = (int)($in['ngn_amount_minor'] ?? 0);
    if ($userId === '' || $ngnAmount <= 0) return ['_code' => 400, 'error' => 'missing_fields'];

    $ref = 'fx:' . bin2hex(random_bytes(8));
    $transfers->create($userId, 'fx', $ref, $ngnAmount, 'NGN', [], 'created');

    $jobs->enqueue($corr, 'fx_convert', [
      'user_id' => $userId,
      'ngn_amount_minor' => $ngnAmount,
      'reference' => $ref
    ], 0, 8);

    $audit->log($corr, $actor, 'fx.enqueued', ['reference' => $ref]);
    return ['reference' => $ref, 'status' => 'queued'];
  });
});

$router->add('POST', '/custody/withdraw', function () {
  withIdempotency('POST:/custody/withdraw', function ($corr, $actor) {
    global $audit, $transfers, $jobs;

    $in = Request::json();
    $userId = (string)($in['user_id'] ?? '');
    $amount = (int)($in['amount_minor'] ?? 0);
    $to = (string)($in['to_address'] ?? '');
    $network = (string)($in['network'] ?? 'BEP20');

    if ($userId === '' || $amount <= 0 || $to === '') return ['_code' => 400, 'error' => 'missing_fields'];

    $ref = 'custody:' . bin2hex(random_bytes(8));
    $transfers->create($userId, 'custody', $ref, $amount, 'USDT', ['to' => $to, 'network' => $network], 'created');

    $jobs->enqueue($corr, 'custody_withdraw', [
      'user_id' => $userId,
      'amount_minor' => $amount,
      'to_address' => $to,
      'network' => $network,
      'reference' => $ref
    ], 0, 8);

    $audit->log($corr, $actor, 'custody.enqueued', ['reference' => $ref]);
    return ['reference' => $ref, 'status' => 'queued'];
  });
});

$router->add('GET', '/audit', function () {
  [$corr] = ctx();
  $cid = $_GET['correlation_id'] ?? '';
  if ($cid === '') { JsonResponse::send(400, ['error' => 'missing_correlation_id'], ['X-Correlation-Id' => $corr]); return; }
  $pdo = Db::pdo();
  $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE correlation_id=? ORDER BY created_at ASC");
  $stmt->execute([(string)$cid]);
  JsonResponse::send(200, ['audit' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], ['X-Correlation-Id' => $corr]);
});

$router->add('GET', '/jobs', function () {
  [$corr] = ctx();
  $cid = $_GET['correlation_id'] ?? '';
  if ($cid === '') { JsonResponse::send(400, ['error' => 'missing_correlation_id'], ['X-Correlation-Id' => $corr]); return; }
  $jobs = (new \App\Queue\JobRepository())->listByCorrelation((string)$cid);
  JsonResponse::send(200, ['jobs' => $jobs], ['X-Correlation-Id' => $corr]);
});

$router->add('GET', '/ledger/entries', function () {
  [$corr] = ctx();
  $ref = $_GET['reference'] ?? '';
  if ($ref === '') { JsonResponse::send(400, ['error' => 'missing_reference'], ['X-Correlation-Id' => $corr]); return; }
  $entries = (new \Domain\Ledger\LedgerService())->entriesByReference((string)$ref);
  JsonResponse::send(200, ['reference' => $ref, 'entries' => $entries], ['X-Correlation-Id' => $corr]);
});

$router->dispatch(Request::method(), Request::path());
