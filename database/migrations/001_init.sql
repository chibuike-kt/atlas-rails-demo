PRAGMA foreign_keys = ON;

-- USERS / ACCOUNTS
CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT UNIQUE NOT NULL,
  created_at TEXT NOT NULL
);

-- External bank accounts linked via open-banking
CREATE TABLE IF NOT EXISTS bank_links (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  status TEXT NOT NULL, -- pending|linked|revoked
  bank_name TEXT,
  account_last4 TEXT,
  external_ref TEXT, -- provider link id
  created_at TEXT NOT NULL,
  linked_at TEXT,
  revoked_at TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Internal ledger accounts (fiat, savings, clearing, revenue, custody)
CREATE TABLE IF NOT EXISTS ledger_accounts (
  id TEXT PRIMARY KEY,
  user_id TEXT, -- nullable for system accounts
  type TEXT NOT NULL, -- user_fiat|user_savings|system_clearing|system_revenue|custody_hot|fx_inventory
  currency TEXT NOT NULL, -- NGN|USD|USDT
  name TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Ledger journal
CREATE TABLE IF NOT EXISTS ledger_entries (
  id TEXT PRIMARY KEY,
  correlation_id TEXT NOT NULL,
  reference TEXT NOT NULL, -- business reference e.g. "p2p:tx_..."
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ledger_lines (
  id TEXT PRIMARY KEY,
  entry_id TEXT NOT NULL,
  account_id TEXT NOT NULL,
  direction TEXT NOT NULL, -- debit|credit
  amount_minor INTEGER NOT NULL, -- minor units (kobo, cents)
  currency TEXT NOT NULL,
  memo TEXT,
  created_at TEXT NOT NULL,
  FOREIGN KEY(entry_id) REFERENCES ledger_entries(id),
  FOREIGN KEY(account_id) REFERENCES ledger_accounts(id)
);

CREATE INDEX IF NOT EXISTS idx_ledger_entries_ref ON ledger_entries(reference);
CREATE INDEX IF NOT EXISTS idx_ledger_lines_entry ON ledger_lines(entry_id);

-- Idempotency (request-level dedupe)
CREATE TABLE IF NOT EXISTS idempotency_keys (
  id TEXT PRIMARY KEY,
  idempotency_key TEXT NOT NULL,
  route TEXT NOT NULL,
  request_hash TEXT NOT NULL,
  response_code INTEGER,
  response_body TEXT,
  status TEXT NOT NULL, -- started|completed|failed
  created_at TEXT NOT NULL,
  completed_at TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_idem_key_route ON idempotency_keys(idempotency_key, route);

-- Audit logs (append-only)
CREATE TABLE IF NOT EXISTS audit_logs (
  id TEXT PRIMARY KEY,
  correlation_id TEXT NOT NULL,
  actor TEXT NOT NULL, -- system|user:<id>
  action TEXT NOT NULL,
  data_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_corr ON audit_logs(correlation_id);

-- Durable outbox / jobs
CREATE TABLE IF NOT EXISTS jobs (
  id TEXT PRIMARY KEY,
  correlation_id TEXT NOT NULL,
  type TEXT NOT NULL, -- openbanking_sync|p2p_transfer|savings_deposit|bill_settlement|fx_convert|custody_withdraw
  payload_json TEXT NOT NULL,
  status TEXT NOT NULL, -- queued|running|succeeded|failed|dead
  attempt INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 8,
  run_at TEXT NOT NULL,
  last_error TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_jobs_run ON jobs(status, run_at);
