CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

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
