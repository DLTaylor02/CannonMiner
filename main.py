import importlib
import pathlib

from bin.collector import Collector
from bin.db_engine import DBEngine
from db_config import DB_CONN_STRING
from maps_key import GOOGLE_API_KEY

SEGMENTS_DIR = pathlib.Path(__file__).parent / "segments"

collector = Collector(GOOGLE_API_KEY)
db = DBEngine(DB_CONN_STRING)

for file in SEGMENTS_DIR.glob("*.py"):
    if file.name.startswith("_"):
        continue

    module_name = f"segments.{file.stem}"
    #print(f"Importing {module_name}")
    segment = importlib.import_module(module_name)

    #print(f"Segment name: {segment.SEGMENT_NAME}")
    db.ensure_segment_table(segment.SEGMENT_NAME)

    result = collector.get_travel_time(
        segment.ORIGIN,
        segment.DESTINATION,
        segment.MODE,
    )

    #print(f"Inserting result for {segment.SEGMENT_NAME}: {result}")
    db.insert_measurement(segment.SEGMENT_NAME, result)