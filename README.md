# Atlas Rails Demo (Systems-Behavior Focus)

This repository is a **backend systems demo** that models fintech-grade behavior:
- reliability (idempotency, durable jobs, retries)
- reversibility (compensations, corrections without mutating history)
- regulatory observability (append-only audit logs, correlation IDs, request tracing)
- strict ledger accounting (double-entry)

UI is intentionally minimal. Interact via `curl`.

## Capabilities Demonstrated

- Bank account linking through open-banking rails (mock provider but real flows)
- Optional funding of internal fiat balance
- Voice-driven rule creation with confirmation gating (voice simulated as transcript text)
- Preview of compiled execution graph (DAG)
- Scheduled execution
- Bank-initiated P2P transfer
- API-routed savings deposit
- Bill aggregator settlement
- NGN -> USD -> USDT conversion
- Crypto withdrawal from custody
- Ledger state changes and immutable journals
- Append-only audit logs
- Idempotency keys on all write routes
- Retry logic after simulated failures

## Architecture Overview

### High-level flow
1. HTTP request enters Router
2. Middleware assigns Correlation ID and enforces Idempotency
3. Domain handlers:
   - write intent to ledger (journal entries)
   - enqueue jobs into durable `jobs` table
   - append audit events
4. Worker process:
   - fetches due jobs
   - executes integration calls (mock providers)
   - retries with exponential backoff
   - writes follow-up ledger entries and audit logs
5. Observability endpoints expose:
   - job timeline
   - ledger references
   - audit trace for a correlation ID

### Core Data Stores (SQLite demo)
- `ledger_entries`, `ledger_lines`: immutable journal and postings
- `idempotency_keys`: request dedupe and stored responses
- `audit_logs`: append-only event trace for regulatory observability
- `jobs`: durable outbox / background execution

## Domain Invariants

### Ledger invariants
- every journal entry must balance per currency:
  sum(debits) == sum(credits)
- postings always occur in minor units (kobo/cents)
- journals are immutable: no updates, only corrective entries

### Idempotency
- all write endpoints accept `Idempotency-Key`
- same key + route returns the stored response if request hash matches
- if hash differs, the request is rejected (prevents accidental replay with new body)

### Reliability & Retry
- jobs use `attempt`, `max_attempts`, `run_at`
- exponential backoff on transient errors
- simulated failures can be injected to demonstrate resilience
- dead-lettering: status `dead` after max attempts

### Reversibility
- flows define compensating actions:
  e.g. bank debit succeeded but downstream failed -> ledger correction + retry
- no direct mutations of monetary history

## Running

### Requirements
- PHP 8.2+
- Composer

### Setup
```bash
composer install
./bin/migrate
./bin/seed
php -S 127.0.0.1:8000 -t public