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
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE role='superadmin') AND EXISTS (SELECT 1 FROM users) THEN
    UPDATE users SET role='superadmin' WHERE id=(SELECT min(id) FROM users);
  END IF;
  UPDATE users SET role='admin'
  WHERE role='superadmin'
    AND id<>(SELECT min(id) FROM users WHERE role='superadmin');
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS users_single_superadmin_idx ON users ((role)) WHERE role='superadmin';

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGSERIAL PRIMARY KEY,
    username_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS login_attempts_username_time_idx ON login_attempts(username_hash, attempted_at DESC);
CREATE INDEX IF NOT EXISTS login_attempts_ip_time_idx ON login_attempts(ip_hash, attempted_at DESC);

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
    finished_at TIMESTAMPTZ,
    calculation_method_version SMALLINT NOT NULL DEFAULT 3
);
ALTER TABLE analysis_jobs ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE analysis_jobs ADD COLUMN IF NOT EXISTS job_type TEXT NOT NULL DEFAULT 'best';
ALTER TABLE analysis_jobs ADD COLUMN IF NOT EXISTS calculation_method_version SMALLINT;
UPDATE analysis_jobs SET calculation_method_version=1 WHERE calculation_method_version IS NULL;
ALTER TABLE analysis_jobs ALTER COLUMN calculation_method_version SET DEFAULT 3;
ALTER TABLE analysis_jobs ALTER COLUMN calculation_method_version SET NOT NULL;
ALTER TABLE analysis_jobs DROP CONSTRAINT IF EXISTS analysis_jobs_job_type_check;
ALTER TABLE analysis_jobs ADD CONSTRAINT analysis_jobs_job_type_check CHECK (job_type IN ('best','custom','automated'));
CREATE INDEX IF NOT EXISTS analysis_jobs_user_time_idx ON analysis_jobs(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS analysis_jobs_method_time_idx ON analysis_jobs(calculation_method_version,created_at DESC);
CREATE INDEX IF NOT EXISTS analysis_jobs_queue_idx ON analysis_jobs(created_at,id) WHERE status='queued';

CREATE TABLE IF NOT EXISTS system_metrics (
    id BIGSERIAL PRIMARY KEY,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    host_cpu_percent NUMERIC(6,2) NOT NULL,
    app_cpu_percent NUMERIC(6,2) NOT NULL,
    disk_total_bytes BIGINT NOT NULL,
    disk_free_bytes BIGINT NOT NULL,
    app_bytes BIGINT NOT NULL,
    cpu_metric_version SMALLINT NOT NULL DEFAULT 2
);
ALTER TABLE system_metrics ADD COLUMN IF NOT EXISTS cpu_metric_version SMALLINT;
ALTER TABLE system_metrics ALTER COLUMN disk_total_bytes DROP NOT NULL;
ALTER TABLE system_metrics ALTER COLUMN disk_free_bytes DROP NOT NULL;
ALTER TABLE system_metrics ALTER COLUMN app_bytes DROP NOT NULL;
UPDATE system_metrics SET cpu_metric_version=1 WHERE cpu_metric_version IS NULL;
ALTER TABLE system_metrics ALTER COLUMN cpu_metric_version SET DEFAULT 2;
ALTER TABLE system_metrics ALTER COLUMN cpu_metric_version SET NOT NULL;
CREATE INDEX IF NOT EXISTS system_metrics_time_idx ON system_metrics(recorded_at DESC);

CREATE TABLE IF NOT EXISTS storage_metrics (
    id BIGSERIAL PRIMARY KEY,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    disk_total_bytes BIGINT NOT NULL,
    disk_free_bytes BIGINT NOT NULL,
    app_bytes BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS storage_metrics_time_idx ON storage_metrics(recorded_at DESC);
INSERT INTO storage_metrics(recorded_at,disk_total_bytes,disk_free_bytes,app_bytes)
SELECT recorded_at,disk_total_bytes,disk_free_bytes,app_bytes FROM (
    SELECT DISTINCT ON ((extract(epoch FROM recorded_at)::bigint/14400))
      recorded_at,disk_total_bytes,disk_free_bytes,app_bytes
    FROM system_metrics WHERE disk_total_bytes IS NOT NULL
    ORDER BY (extract(epoch FROM recorded_at)::bigint/14400),recorded_at DESC
) legacy_storage
WHERE NOT EXISTS (SELECT 1 FROM storage_metrics);

CREATE TABLE IF NOT EXISTS google_api_requests (
    id BIGSERIAL PRIMARY KEY,
    service TEXT NOT NULL CHECK (service IN ('directions','static_map')),
    requested_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS google_api_requests_time_idx ON google_api_requests(requested_at DESC);

CREATE TABLE IF NOT EXISTS segments (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE CHECK (name ~ '^[a-z0-9]+_to_[a-z0-9]+$'),
    start_node TEXT NOT NULL,
    end_node TEXT NOT NULL,
    origin TEXT NOT NULL,
    destination TEXT NOT NULL,
    timezone TEXT NOT NULL DEFAULT 'America/New_York',
    travel_mode TEXT NOT NULL DEFAULT 'driving',
    enabled BOOLEAN NOT NULL DEFAULT TRUE
);
ALTER TABLE segments ADD COLUMN IF NOT EXISTS timezone TEXT NOT NULL DEFAULT 'America/New_York';
UPDATE segments SET timezone=CASE start_node
  WHEN 'big' THEN 'America/Denver'
  WHEN 'stl' THEN 'America/Chicago'
  WHEN 'nash' THEN 'America/Chicago'
  WHEN 'den' THEN 'America/Denver'
  WHEN 'elr' THEN 'America/Chicago'
  WHEN 'cov' THEN 'America/Denver'
  WHEN 'bar' THEN 'America/Los_Angeles'
  ELSE 'America/New_York'
END WHERE start_node IN ('redball','har','you','cole','coln','big','stl','nash','den','elr','cov','bar')
  AND timezone='America/New_York';

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
