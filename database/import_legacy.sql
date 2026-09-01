-- Import original segment_* observation tables. Forecast/estimation tables are
-- intentionally ignored. Keeping the source table and id makes this safe to
-- run after every upgrade.
CREATE TABLE IF NOT EXISTS legacy_measurement_imports (
    source_table TEXT NOT NULL,
    source_id BIGINT NOT NULL,
    measurement_id BIGINT NOT NULL REFERENCES measurements(id) ON DELETE CASCADE,
    PRIMARY KEY (source_table, source_id)
);

-- The chunked PHP importer performs the data copy so setup can report progress.
