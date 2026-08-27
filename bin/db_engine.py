import json
from psycopg import connect
from psycopg.sql import Identifier, SQL

class DBEngine:
    def __init__(self, conn_str: str):
        self.conn_str = conn_str

    def ensure_segment_table(self, segment_name: str, prefix: str = "segment_"):
        """
        Creates table if it doesn't exist.
        prefix='segment_' for measurements,
        'segmentestimate_' for forecast data.
        """
        table = f"{prefix}{segment_name}"
        query = SQL("""
        CREATE TABLE IF NOT EXISTS {table} (
            id BIGSERIAL PRIMARY KEY,
            collected_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            duration_seconds INTEGER NOT NULL,
            duration_in_traffic_seconds INTEGER,
            distance_meters INTEGER,
            raw_payload JSONB NOT NULL
        );
        """).format(table=Identifier(table))

        with connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute(query)
            conn.commit()

    def insert_measurement(self, segment_name: str, data: dict, prefix: str = "segment_", collected_at=None):
        """
        Inserts a measurement.
        If collected_at is provided, it will override the default 'now()'.
        """
        table = f"{prefix}{segment_name}"
        if collected_at is None:
            # default behavior — let Postgres handle timestamp
            query = SQL("""
            INSERT INTO {table} 
            (duration_seconds, duration_in_traffic_seconds, distance_meters, raw_payload)
            VALUES (%s, %s, %s, %s)
            """).format(table=Identifier(table))
            values = (
                data["duration"],
                data["duration_in_traffic"],
                data.get("distance"),
                json.dumps(data["raw"]),
            )
        else:
            # override timestamp with provided collected_at
            query = SQL("""
            INSERT INTO {table} 
            (collected_at, duration_seconds, duration_in_traffic_seconds, distance_meters, raw_payload)
            VALUES (%s, %s, %s, %s, %s)
            """).format(table=Identifier(table))
            values = (
                collected_at,
                data["duration"],
                data["duration_in_traffic"],
                data.get("distance"),
                json.dumps(data["raw"]),
            )

        with connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute(query, values)
            conn.commit()
