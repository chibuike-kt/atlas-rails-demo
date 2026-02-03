PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT UNIQUE NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS bank_links (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  status TEXT NOT NULL,
  bank_name TEXT,
  account_last4 TEXT,
  external_ref TEXT,
  created_at TEXT NOT NULL,
  linked_at TEXT,
  revoked_at TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS ledger_accounts (
  id TEXT PRIMARY KEY,
  user_id TEXT,
  type TEXT NOT NULL,
  currency TEXT NOT NULL,
  name TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS ledger_entries (
  id TEXT PRIMARY KEY,
  correlation_id TEXT NOT NULL,
  reference TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ledger_lines (
  id TEXT PRIMARY KEY,
  entry_id TEXT NOT NULL,
  account_id TEXT NOT NULL,
  direction TEXT NOT NULL,
  amount_minor INTEGER NOT NULL,
  currency TEXT NOT NULL,
  memo TEXT,
  created_at TEXT NOT NULL,
  FOREIGN KEY(entry_id) REFERENCES ledger_entries(id),
  FOREIGN KEY(account_id) REFERENCES ledger_accounts(id)
);

CREATE INDEX IF NOT EXISTS idx_ledger_entries_ref ON ledger_entries(reference);
CREATE INDEX IF NOT EXISTS idx_ledger_lines_entry ON ledger_lines(entry_id);
CREATE INDEX IF NOT EXISTS idx_ledger_lines_account ON ledger_lines(account_id);

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id TEXT PRIMARY KEY,
  idempotency_key TEXT NOT NULL,
  route TEXT NOT NULL,
  request_hash TEXT NOT NULL,
  response_code INTEGER,
  response_body TEXT,
  status TEXT NOT NULL,
  created_at TEXT NOT NULL,
  completed_at TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_idem_key_route ON idempotency_keys(idempotency_key, route);

CREATE TABLE IF NOT EXISTS audit_logs (
  id TEXT PRIMARY KEY,
  correlation_id TEXT NOT NULL,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  data_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_corr ON audit_logs(correlation_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action);

CREATE TABLE IF NOT EXISTS jobs (
  id TEXT PRIMARY KEY,
  correlation_id TEXT NOT NULL,
  type TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  status TEXT NOT NULL,
  attempt INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 8,
  run_at TEXT NOT NULL,
  last_error TEXT,
  locked_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_jobs_run ON jobs(status, run_at);
CREATE INDEX IF NOT EXISTS idx_jobs_corr ON jobs(correlation_id);

CREATE TABLE IF NOT EXISTS rules (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  status TEXT NOT NULL,
  transcript TEXT NOT NULL,
  proposed_json TEXT NOT NULL,
  confirmed_json TEXT,
  created_at TEXT NOT NULL,
  confirmed_at TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS rule_graphs (
  id TEXT PRIMARY KEY,
  rule_id TEXT NOT NULL,
  graph_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(rule_id) REFERENCES rules(id)
);

-- ✅ THIS IS THE TABLE YOU'RE MISSING
CREATE TABLE IF NOT EXISTS schedules (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  rule_id TEXT NOT NULL,
  schedule_type TEXT NOT NULL,
  schedule_value TEXT NOT NULL,
  next_run_at TEXT NOT NULL,
  status TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id),
  FOREIGN KEY(rule_id) REFERENCES rules(id)
);

CREATE INDEX IF NOT EXISTS idx_schedules_next ON schedules(status, next_run_at);

CREATE TABLE IF NOT EXISTS transfers (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  kind TEXT NOT NULL,
  status TEXT NOT NULL,
  reference TEXT NOT NULL,
  amount_minor INTEGER NOT NULL,
  currency TEXT NOT NULL,
  meta_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_transfers_ref ON transfers(reference);
