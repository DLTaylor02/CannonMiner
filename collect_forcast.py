#!/usr/bin/env python3
import sys
from datetime import datetime
from pathlib import Path
import time
import json
import requests
from requests.exceptions import RequestException
from tqdm import tqdm
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type, RetryError
from concurrent.futures import ThreadPoolExecutor, as_completed

# Project imports
from db_config import DB_CONN_STRING
from bin.db_engine import DBEngine
from maps_key import GOOGLE_API_KEY

# --- 1. Parse arguments ---
if len(sys.argv) != 3:
    print("Usage: python collect_forecast.py <year> <month>")
    sys.exit(1)

year = int(sys.argv[1])
month = int(sys.argv[2])
if month < 1 or month > 12:
    raise ValueError("Month must be 1-12")

# --- 2. Load segments ---
import importlib.util

SEGMENTS_DIR = Path(__file__).parent / "segments"

def load_segments():
    segments = []
    for file_path in SEGMENTS_DIR.glob("*.py"):
        if file_path.name == "__init__.py":
            continue  # skip __init__.py

        spec = importlib.util.spec_from_file_location(file_path.stem, file_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)

        if not hasattr(module, "SEGMENT_NAME"):
            continue

        segments.append({
            "name": getattr(module, "SEGMENT_NAME"),
            "origin": getattr(module, "ORIGIN"),
            "destination": getattr(module, "DESTINATION"),
            "mode": getattr(module, "MODE", "driving")
        })
    return segments

all_segments = load_segments()

# --- 3. Generate hourly timestamps ---
from calendar import monthrange

def generate_hourly_times(year: int, month: int):
    num_days = monthrange(year, month)[1]
    hours = []
    for day in range(1, num_days + 1):
        for hour in range(24):
            hours.append(datetime(year, month, day, hour))
    return hours

hourly_times = generate_hourly_times(year, month)

# --- 4. Setup DB engine ---
db = DBEngine(DB_CONN_STRING)

# --- 5. Google Maps API request with retries ---
BASE_URL = "https://maps.googleapis.com/maps/api/directions/json"

class GoogleMapsAPIError(Exception):
    pass

def create_session():
    """Create a requests session with connection reuse"""
    session = requests.Session()
    session.headers.update({"User-Agent": "CannonMiner/1.0"})
    return session

@retry(
    stop=stop_after_attempt(5),
    wait=wait_exponential(multiplier=1, min=2, max=20),
    retry=(
        retry_if_exception_type(GoogleMapsAPIError) |
        retry_if_exception_type(RequestException)
    )
)
def get_directions_with_traffic(session: requests.Session, origin, destination, departure_dt):
    unix_time = int(departure_dt.timestamp())
    params = {
        "origin": origin,
        "destination": destination,
        "mode": "driving",
        "departure_time": unix_time,
        "traffic_model": "best_guess",
        "key": GOOGLE_API_KEY
    }
    resp = session.get(BASE_URL, params=params, timeout=15)
    data = resp.json()

    if resp.status_code != 200 or data.get("status") != "OK":
        raise GoogleMapsAPIError(f"{origin} -> {destination} at {departure_dt}: {data.get('status')}")

    route = data["routes"][0]["legs"][0]
    return {
        "duration": route["duration"]["value"],
        "duration_in_traffic": route.get("duration_in_traffic", {}).get("value"),
        "distance": route.get("distance", {}).get("value"),
        "raw": data
    }

# --- 6. Helper for checkpointing ---
def get_collected_hours(segment_name):
    """Return a set of datetime objects already collected in DB"""
    table = f"segmentestimate_{segment_name}"
    try:
        with db.connect() as conn:
            with conn.cursor() as cur:
                cur.execute(f"SELECT collected_at FROM {table}")
                rows = cur.fetchall()
                return set(r[0].replace(tzinfo=None) for r in rows)
    except Exception:
        # If table does not exist yet or DB error, return empty set
        return set()

# --- 7. Collection loop with threading, segment-level backoff, checkpointing ---
MAX_WORKERS = 3  # small batch size for PiHole stability
SEGMENT_RETRIES = 5  # segment-level retry attempts

for segment in all_segments:
    db.ensure_segment_table(segment["name"], prefix="segmentestimate_")
    print(f"Collecting segment {segment['name']} with {len(hourly_times)} hours")

    session = create_session()
    collected_hours = get_collected_hours(segment["name"])
    to_collect = [dt for dt in hourly_times if dt not in collected_hours]

    attempt = 0
    while attempt < SEGMENT_RETRIES and to_collect:
        try:
            with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
                future_to_dt = {
                    executor.submit(get_directions_with_traffic, session, segment["origin"], segment["destination"], dt): dt
                    for dt in to_collect
                }

                for future in tqdm(as_completed(future_to_dt), total=len(future_to_dt),
                                   desc=f"Segment {segment['name']}"):
                    dt = future_to_dt[future]
                    try:
                        data = future.result()
                        db.insert_measurement(
                            segment["name"],
                            data,
                            prefix="segmentestimate_",
                            collected_at=dt
                        )
                    except RetryError as e:
                        print(f"Skipping {segment['name']} at {dt} due to repeated network/API errors: {e}")
                    except Exception as e:
                        print(f"Unexpected error for {segment['name']} at {dt}: {e}")
                    time.sleep(0.05)
            # Break loop if successful
            break
        except Exception as e:
            attempt += 1
            wait_time = 2 ** attempt
            print(f"Segment {segment['name']} failed attempt {attempt}/{SEGMENT_RETRIES}: {e}. "
                  f"Retrying after {wait_time}s...")
            time.sleep(wait_time)

print("Forecast collection complete!")
