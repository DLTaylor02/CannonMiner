-- Import original segment_* observation tables. Forecast/estimation tables are
-- intentionally ignored. Keeping the source table and id makes this safe to
-- run after every upgrade.
CREATE TABLE IF NOT EXISTS legacy_measurement_imports (
    source_table TEXT NOT NULL,
    source_id BIGINT NOT NULL,
    measurement_id BIGINT NOT NULL REFERENCES measurements(id) ON DELETE CASCADE,
    PRIMARY KEY (source_table, source_id)
);

DO $$
DECLARE legacy RECORD; segment_key TEXT;
BEGIN
  FOR legacy IN
    SELECT table_name FROM information_schema.tables
    WHERE table_schema='public'
      AND table_name LIKE 'segment\_%' ESCAPE '\'
    ORDER BY table_name
  LOOP
    segment_key := substring(legacy.table_name FROM 9);
    IF EXISTS (SELECT 1 FROM segments WHERE name=segment_key) THEN
      EXECUTE format(
        'WITH imported AS (
           INSERT INTO measurements(segment_id,collected_at,duration_seconds,duration_in_traffic_seconds,distance_meters,raw_payload)
           SELECT s.id,l.collected_at,l.duration_seconds,l.duration_in_traffic_seconds,l.distance_meters,l.raw_payload
           FROM %I l JOIN segments s ON s.name=%L
           LEFT JOIN legacy_measurement_imports i ON i.source_table=%L AND i.source_id=l.id
           WHERE i.source_id IS NULL
           RETURNING id
         ), source_rows AS (
           SELECT l.id, row_number() OVER (ORDER BY l.id) AS row_number
           FROM %I l LEFT JOIN legacy_measurement_imports i ON i.source_table=%L AND i.source_id=l.id
           WHERE i.source_id IS NULL
         ), inserted_rows AS (
           SELECT id, row_number() OVER (ORDER BY id) AS row_number FROM imported
         )
         INSERT INTO legacy_measurement_imports(source_table,source_id,measurement_id)
         SELECT %L,s.id,n.id FROM source_rows s JOIN inserted_rows n USING(row_number)',
        legacy.table_name, segment_key, legacy.table_name, legacy.table_name, legacy.table_name, legacy.table_name
      );
    END IF;
  END LOOP;
END $$;
