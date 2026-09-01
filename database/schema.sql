CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('superadmin','admin','user')),
    theme TEXT NOT NULL DEFAULT 'adaptive' CHECK (theme IN ('light','dark','adaptive')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'user';
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme TEXT NOT NULL DEFAULT 'adaptive';
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE role='superadmin') AND EXISTS (SELECT 1 FROM users) THEN
    UPDATE users SET role='superadmin' WHERE id=(SELECT min(id) FROM users);
  END IF;
  UPDATE users SET role='admin'
  WHERE role='superadmin'
    AND id<>(SELECT min(id) FROM users WHERE role='superadmin');
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS users_single_superadmin_idx ON users ((role)) WHERE role='superadmin';

CREATE TABLE IF NOT EXISTS analysis_jobs (
    id UUID PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('queued','running','complete','failed')),
    input JSONB NOT NULL,
    progress_current INTEGER NOT NULL DEFAULT 0,
    progress_total INTEGER NOT NULL DEFAULT 1,
    stage TEXT NOT NULL DEFAULT 'Queued',
    eta_seconds INTEGER,
    result JSONB,
    error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ
);
ALTER TABLE analysis_jobs ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
CREATE INDEX IF NOT EXISTS analysis_jobs_user_time_idx ON analysis_jobs(user_id,created_at DESC);

CREATE TABLE IF NOT EXISTS segments (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE CHECK (name ~ '^[a-z0-9]+_to_[a-z0-9]+$'),
    start_node TEXT NOT NULL,
    end_node TEXT NOT NULL,
    origin TEXT NOT NULL,
    destination TEXT NOT NULL,
    travel_mode TEXT NOT NULL DEFAULT 'driving',
    enabled BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS measurements (
    id BIGSERIAL PRIMARY KEY,
    segment_id BIGINT NOT NULL REFERENCES segments(id) ON DELETE CASCADE,
    collected_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    duration_seconds INTEGER NOT NULL,
    duration_in_traffic_seconds INTEGER,
    distance_meters INTEGER,
    raw_payload JSONB NOT NULL
);
CREATE INDEX IF NOT EXISTS measurements_segment_time_idx ON measurements (segment_id, collected_at);

CREATE TABLE IF NOT EXISTS collection_runs (
    id BIGSERIAL PRIMARY KEY,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ,
    status TEXT NOT NULL CHECK (status IN ('running', 'success', 'failed')),
    segments_collected INTEGER NOT NULL DEFAULT 0,
    message TEXT
);
CREATE INDEX IF NOT EXISTS collection_runs_status_time_idx ON collection_runs (status, started_at DESC);
