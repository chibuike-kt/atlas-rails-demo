# Atlas Rails Demo

This repository demonstrates a financial rails simulation focused on reliability, reversibility, and regulatory observability rather than UI.

The system models:

bank account linking via open banking style flows  
asynchronous fiat funding  
voice driven rule creation and compilation  
execution graph preview  
scheduled automation  
bank initiated P2P transfers  
bill aggregation settlement  
multi leg FX conversion NGN to USD to USDT  
custodial crypto withdrawal  
double entry ledger posting  
audit logs  
idempotency keys  
retry logic with simulated failures  

All money movement is processed asynchronously through a job queue and worker to mimic production grade financial infrastructure.

---

## Requirements

PHP 8.2 or newer  
Composer  
SQLite  
Postman or any HTTP client  
Git Bash or WSL on Windows recommended  

---

## Initial Setup

From the repository root:

composer install
composer dump-autoload -o

mkdir -p storage

rm -f storage/demo.sqlite

./bin/migrate
./bin/seed


---

## Running the System

Three processes must run simultaneously.

Terminal 1 API server



php -S 127.0.0.1:8000 -t public


Terminal 2 background worker



./bin/worker


Terminal 3 scheduler loop



while true; do ./bin/scheduler; sleep 1; done


Do not proceed until all three are running.

---

## Postman Environment Setup

Create a Postman environment with the following variables:

baseUrl http://127.0.0.1:8000  
userId empty  
bankRef empty  
ruleId empty  

---

## Demo Execution Flow

Requests must be sent in the order below.

---

### Health Check

GET



{{baseUrl}}/health


---

### Create User

POST



{{baseUrl}}/users


Headers



Content-Type application/json
Idempotency-Key create-user-001
X-Correlation-Id corr-user-001


Body



{
"email": "demo@example.com
"
}


Save the returned user id into the Postman environment variable userId.

---

### Start Bank Linking

POST



{{baseUrl}}/bank_links/start


Headers



Content-Type application/json
Idempotency-Key link-start-001
X-Correlation-Id corr-link-001


Body



{
"user_id": "{{userId}}"
}


Save external_ref as bankRef.

---

### Complete Bank Linking

POST



{{baseUrl}}/bank_links/complete


Headers



Content-Type application/json
Idempotency-Key link-complete-001
X-Correlation-Id corr-link-002


Body



{
"external_ref": "{{bankRef}}"
}


---

### Fund Fiat Balance

POST



{{baseUrl}}/fiat/fund


Headers



Content-Type application/json
Idempotency-Key fund-001
X-Correlation-Id corr-fund-001


Body



{
"user_id": "{{userId}}",
"amount_minor": 500000,
"bank_external_ref": "{{bankRef}}"
}


Expected response shows status queued.  
Settlement happens asynchronously.

Inspect:

GET



{{baseUrl}}/jobs?correlation_id=corr-fund-001


GET



{{baseUrl}}/audit?correlation_id=corr-fund-001


GET



{{baseUrl}}/balances?user_id={{userId}}


---

### Voice Rule Proposal

POST



{{baseUrl}}/voice/rules/propose


Headers



Content-Type application/json
Idempotency-Key rule-propose-001
X-Correlation-Id corr-rule-001


Body



{
"user_id": "{{userId}}",
"transcript": "Every 30 seconds move 1000 NGN into savings"
}


Save rule_id.

---

### Confirm Rule and Generate Execution Graph

POST



{{baseUrl}}/voice/rules/confirm


Headers



Content-Type application/json
Idempotency-Key rule-confirm-001
X-Correlation-Id corr-rule-002


Body



{
"rule_id": "{{ruleId}}",
"confirm": true
}


Preview DAG:

GET



{{baseUrl}}/rules/graph?rule_id={{ruleId}}


---

### Create Schedule

POST



{{baseUrl}}/schedules


Headers



Content-Type application/json
Idempotency-Key sched-001
X-Correlation-Id corr-sched-001


Body



{
"user_id": "{{userId}}",
"rule_id": "{{ruleId}}",
"schedule_type": "interval_seconds",
"schedule_value": "10"
}


After several seconds scheduled deposits should execute.

Verify balances.

---

### Bank P2P Transfer

POST



{{baseUrl}}/transfers/p2p


---

### Bill Settlement

POST



{{baseUrl}}/bills/settle


---

### FX Conversion

POST



{{baseUrl}}/fx/convert


---

### Custody Withdrawal

POST



{{baseUrl}}/custody/withdraw


---

## Observability and Verification

Audit trail



GET {{baseUrl}}/audit?correlation_id=corr-fx-001


Jobs queue



GET {{baseUrl}}/jobs?correlation_id=corr-fx-001


Ledger entries



GET {{baseUrl}}/ledger/entries?reference=...


Balances



GET {{baseUrl}}/balances?user_id={{userId}}


---

## Idempotency Testing

Resend the same request with the same Idempotency Key and identical body.

The server should replay the stored response and balances must not change.

If the request body differs the system will return idempotency_hash_mismatch.

---

## Retry Simulation

Restart the worker with failpoints enabled.



FAILPOINTS="fiat_fund:40,fx_convert:50,p2p_transfer:30,bill_settlement:40,custody_withdraw:35" ./bin/worker


Jobs will fail randomly and retry until success or max attempts.

Observe attempt counts and audit logs.

---

## System Guarantees Demonstrated

Asynchronous settlement  
Exactly once semantics through idempotency  
Atomic ledger posting  
Multi leg FX journaling  
Durable jobs  
Retry with backoff  
Audit logging  
Correlation tracing  
Schedule driven execution  
Custody inventory accounting  

