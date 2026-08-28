#!/usr/bin/env python3
"""Find a fast route and departure time with a quantified delay risk.

Examples:
  python router.py redball portofino 110
  python router.py redball portofino 110 --profile reliability
  python router.py redball portofino 110 --profile balanced --max-delay-risk 0.15

The requested average speed establishes the no-delay travel time. The router
uses recorded traffic delay (``duration_in_traffic - duration``) to predict
the additional delay at each segment's estimated arrival time. Sparse buckets
are deliberately shrunk toward all-time segment behaviour rather than being
treated as certain.
"""

from __future__ import annotations

import argparse
import hashlib
import math
import os
from concurrent.futures import FIRST_COMPLETED, ProcessPoolExecutor, wait
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from itertools import islice
from typing import Dict, Iterable, List, Optional, Sequence, Tuple
from zoneinfo import ZoneInfo

import networkx as nx
import numpy as np
from psycopg import connect
from psycopg.rows import dict_row
from psycopg.sql import Identifier, SQL
from tenacity import retry, stop_after_attempt, wait_exponential_jitter
from tqdm import tqdm

from db_config import DB_CONN_STRING


METER_PER_MILE = 1609.344
EASTERN_TIME = ZoneInfo("America/New_York")
# A delay event is material when total congestion is at least five minutes or
# two percent of the target-speed drive time, whichever is greater.
MIN_MATERIAL_DELAY_SECONDS = 5 * 60
MATERIAL_DELAY_DRIVE_TIME_SHARE = 0.02
MIN_NONTRIVIAL_SEGMENT_DELAY_SECONDS = 2 * 60
NONTRIVIAL_SEGMENT_DELAY_SHARE = 0.05
RISK_SIMULATION_COUNT = 1024
EMPIRICAL_SAMPLE_POINTS = 64


@dataclass(frozen=True)
class Segment:
    name: str
    start: str
    end: str


@dataclass(frozen=True)
class Sample:
    collected_at: datetime
    duration_seconds: float
    traffic_seconds: float
    distance_meters: float


@dataclass(frozen=True)
class SegmentPrediction:
    mean_delay_seconds: float
    distance_meters: float
    normal_duration_seconds: float
    nearby_samples: int
    local_share: float
    matching_delay_points: np.ndarray


@dataclass(frozen=True)
class RouteEvaluation:
    segments: Sequence[Segment]
    departure: datetime
    achievable_driving_seconds: float
    expected_congestion_seconds: float
    expected_seconds: float
    delay_risk: float
    total_distance_meters: float
    matching_observations: int


# Set once in each process by ``initialise_worker``. Keeping the data in worker
# state avoids serialising the full sample dataset for every work item.
WORKER_SEGMENT_SAMPLES: Dict[Segment, List[Sample]] = {}
WORKER_TRIP_TIMEZONE: Optional[ZoneInfo] = None
WORKER_TARGET_MPH = 0.0
WORKER_PREDICTION_CACHE: Dict[Tuple[Segment, int, int, int, int], SegmentPrediction] = {}
WORKER_DELAY_POINTS: Dict[Segment, np.ndarray] = {}


def format_duration(seconds: float) -> str:
    seconds = max(0, int(round(seconds)))
    hours, remainder = divmod(seconds, 3600)
    minutes, seconds = divmod(remainder, 60)
    return f"{hours:02d}:{minutes:02d}:{seconds:02d}"


def meters_to_miles(meters: float) -> float:
    return meters / METER_PER_MILE


def mph_to_mps(mph: float) -> float:
    return mph * METER_PER_MILE / 3600.0


def ensure_utc(value: datetime) -> datetime:
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


@retry(stop=stop_after_attempt(5), wait=wait_exponential_jitter(0.5, 5))
def db_fetchall(query, params: Tuple = ()) -> List[dict]:
    with connect(DB_CONN_STRING, row_factory=dict_row) as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchall()


def list_segment_tables() -> List[str]:
    rows = db_fetchall(
        """
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_type = 'BASE TABLE'
          AND (LEFT(table_name, 8) = 'segment_' OR
               LEFT(table_name, 16) = 'segmentestimate_')
        ORDER BY table_name
        """
    )
    return [row["table_name"] for row in rows]


def parse_segment_table(table: str) -> Optional[Segment]:
    if table.startswith("segmentestimate_"):
        name = table[len("segmentestimate_"):]
    elif table.startswith("segment_"):
        name = table[len("segment_"):]
    else:
        return None
    if "_to_" not in name:
        return None
    start, end = name.split("_to_", 1)
    return Segment(name=name, start=start, end=end)


def load_samples(table: str) -> List[Sample]:
    """Load a table whose name came from PostgreSQL metadata, safely quoted."""
    query = SQL("""
        SELECT collected_at, duration_seconds, duration_in_traffic_seconds, distance_meters
        FROM public.{table}
        WHERE collected_at IS NOT NULL
          AND duration_in_traffic_seconds IS NOT NULL
          AND duration_in_traffic_seconds > 0
          AND duration_seconds IS NOT NULL
          AND duration_seconds > 0
          AND distance_meters IS NOT NULL
          AND distance_meters > 0
        ORDER BY collected_at
    """).format(table=Identifier(table))
    rows = db_fetchall(query)
    samples: List[Sample] = []
    for row in rows:
        try:
            samples.append(
                Sample(
                    collected_at=ensure_utc(row["collected_at"]),
                    duration_seconds=float(row["duration_seconds"]),
                    traffic_seconds=float(row["duration_in_traffic_seconds"]),
                    distance_meters=float(row["distance_meters"]),
                )
            )
        except (TypeError, ValueError, OverflowError):
            continue
    return samples


def load_segment_samples() -> Dict[Segment, List[Sample]]:
    """Combine observed and forecast tables for the same logical segment."""
    grouped: Dict[Segment, List[Sample]] = {}
    tables = list_segment_tables()
    for table in tqdm(tables, desc="Loading segment tables", unit="table"):
        segment = parse_segment_table(table)
        if segment is not None:
            grouped.setdefault(segment, []).extend(load_samples(table))
    return grouped


def median_distance(samples: Sequence[Sample]) -> float:
    return float(np.median([sample.distance_meters for sample in samples]))


def build_graph(segment_samples: Dict[Segment, List[Sample]], target_mph: float) -> nx.DiGraph:
    graph = nx.DiGraph()
    for segment, samples in segment_samples.items():
        if samples:
            graph.add_edge(
                segment.start,
                segment.end,
                segment=segment,
                # Candidate routes are bounded, so use target-speed travel time
                # plus typical measured congestion delay as the initial ranking.
                weight=(median_distance(samples) / mph_to_mps(target_mph)
                        + float(np.mean([max(0.0, sample.traffic_seconds - sample.duration_seconds)
                                         for sample in samples]))),
            )
    return graph


def circular_minute_distance(first: datetime, second: datetime) -> int:
    first_minute = first.hour * 60 + first.minute
    second_minute = second.hour * 60 + second.minute
    difference = abs(first_minute - second_minute)
    return min(difference, 24 * 60 - difference)


def month_distance(first: datetime, second: datetime) -> int:
    difference = abs(first.month - second.month)
    return min(difference, 12 - difference)


def empirical_points(values: np.ndarray, weights: Optional[np.ndarray] = None) -> np.ndarray:
    """Compress a delay distribution into equally probable quantile points."""
    if weights is None and len(values) <= EMPIRICAL_SAMPLE_POINTS:
        return values.astype(np.float32, copy=True)
    order = np.argsort(values)
    sorted_values = values[order]
    if weights is None:
        cumulative = (np.arange(len(sorted_values)) + 0.5) / len(sorted_values)
    else:
        sorted_weights = weights[order]
        cumulative = np.cumsum(sorted_weights) / sorted_weights.sum()
    targets = (np.arange(EMPIRICAL_SAMPLE_POINTS) + 0.5) / EMPIRICAL_SAMPLE_POINTS
    return np.interp(targets, cumulative, sorted_values).astype(np.float32)


def prediction_for(
    samples: Sequence[Sample], arrival: datetime, trip_timezone: ZoneInfo
) -> SegmentPrediction:
    """Predict a segment at arrival using recurring local traffic patterns."""
    if not samples:
        raise ValueError("Cannot predict a segment with no valid samples")

    local_arrival = arrival.astimezone(trip_timezone)
    # The requested Cannonball pace is the baseline. Historic API data supplies
    # only the congestion penalty, which remains meaningful at a higher pace.
    values = np.asarray(
        [max(0.0, sample.traffic_seconds - sample.duration_seconds) for sample in samples],
        dtype=float,
    )
    distances = np.asarray([sample.distance_meters for sample in samples], dtype=float)
    global_mean = float(np.mean(values))
    normal_durations = np.asarray([sample.duration_seconds for sample in samples], dtype=float)

    weighted_values: List[float] = []
    weights: List[float] = []
    for sample in samples:
        observed = sample.collected_at.astimezone(trip_timezone)
        minute_gap = circular_minute_distance(observed, local_arrival)
        if observed.weekday() != local_arrival.weekday() or minute_gap > 90:
            continue
        time_weight = math.exp(-minute_gap / 45.0)
        season_weight = 1.0 if month_distance(observed, local_arrival) <= 1 else 0.35
        weighted_values.append(max(0.0, sample.traffic_seconds - sample.duration_seconds))
        weights.append(time_weight * season_weight)

    nearby_count = len(weighted_values)
    if nearby_count:
        nearby = np.asarray(weighted_values, dtype=float)
        nearby_weights = np.asarray(weights, dtype=float)
        local_mean = float(np.average(nearby, weights=nearby_weights))
    else:
        local_mean = global_mean

    # Ten relevant observations make the local pattern dominant.
    local_share = nearby_count / (nearby_count + 10.0)
    mean = local_share * local_mean + (1.0 - local_share) * global_mean

    return SegmentPrediction(
        mean_delay_seconds=mean,
        distance_meters=float(np.median(distances)),
        normal_duration_seconds=float(np.median(normal_durations)),
        nearby_samples=nearby_count,
        local_share=local_share,
        matching_delay_points=empirical_points(
            np.asarray(weighted_values, dtype=float), np.asarray(weights, dtype=float)
        ) if weighted_values else np.empty(0, dtype=np.float32),
    )


def stable_seed(route: Sequence[Segment], departure: datetime) -> int:
    value = "|".join(segment.name for segment in route) + "|" + departure.isoformat()
    return int.from_bytes(hashlib.blake2b(value.encode(), digest_size=8).digest(), "little")


def sample_segment_delays(
    prediction: SegmentPrediction, all_delay_points: np.ndarray, random: np.random.Generator
) -> np.ndarray:
    """Draw from compact blended local/global empirical distributions."""
    simulated = random.choice(all_delay_points, size=RISK_SIMULATION_COUNT)
    if prediction.matching_delay_points.size == 0 or prediction.local_share == 0:
        return simulated

    use_local = random.random(RISK_SIMULATION_COUNT) < prediction.local_share
    local_count = int(np.count_nonzero(use_local))
    if local_count:
        simulated[use_local] = random.choice(
            prediction.matching_delay_points,
            size=local_count,
        )
    return simulated


def evaluate_route(
    route: Sequence[Segment],
    departure: datetime,
    segment_samples: Dict[Segment, List[Sample]],
    trip_timezone: ZoneInfo,
    target_mph: float,
    prediction_cache: Dict[Tuple[Segment, int, int, int, int], SegmentPrediction],
    delay_points: Dict[Segment, np.ndarray],
) -> RouteEvaluation:
    arrival = departure
    achievable_driving_seconds = 0.0
    expected_congestion_seconds = 0.0
    expected_seconds = 0.0
    total_distance = 0.0
    support = 0
    predictions: List[Tuple[Segment, SegmentPrediction, float]] = []

    for segment in route:
        local_arrival = arrival.astimezone(trip_timezone)
        bucket_minute = (local_arrival.minute // 30) * 30
        cache_key = (
            segment,
            local_arrival.weekday(),
            local_arrival.month,
            local_arrival.hour,
            bucket_minute,
        )
        prediction = prediction_cache.get(cache_key)
        if prediction is None:
            bucketed_arrival = local_arrival.replace(minute=bucket_minute, second=0, microsecond=0)
            prediction = prediction_for(segment_samples[segment], bucketed_arrival, trip_timezone)
            prediction_cache[cache_key] = prediction
        target_speed_seconds = prediction.distance_meters / mph_to_mps(target_mph)
        expected_seconds += target_speed_seconds + prediction.mean_delay_seconds
        achievable_driving_seconds += target_speed_seconds
        expected_congestion_seconds += prediction.mean_delay_seconds
        total_distance += prediction.distance_meters
        support += prediction.nearby_samples
        predictions.append((segment, prediction, target_speed_seconds))
        arrival += timedelta(seconds=target_speed_seconds + prediction.mean_delay_seconds)

    material_delay_threshold = max(
        MIN_MATERIAL_DELAY_SECONDS,
        achievable_driving_seconds * MATERIAL_DELAY_DRIVE_TIME_SHARE,
    )
    random = np.random.default_rng(stable_seed(route, departure))
    total_simulated_congestion = np.zeros(RISK_SIMULATION_COUNT)
    nontrivial_slowdown = np.zeros(RISK_SIMULATION_COUNT, dtype=bool)
    for segment, prediction, target_speed_seconds in predictions:
        simulated_delay = sample_segment_delays(prediction, delay_points[segment], random)
        total_simulated_congestion += simulated_delay
        nontrivial_threshold = max(
            MIN_NONTRIVIAL_SEGMENT_DELAY_SECONDS,
            prediction.normal_duration_seconds * NONTRIVIAL_SEGMENT_DELAY_SHARE,
        )
        nontrivial_slowdown |= simulated_delay >= nontrivial_threshold

    material_route_delay = total_simulated_congestion >= material_delay_threshold
    delay_risk = float(np.mean(nontrivial_slowdown | material_route_delay))
    return RouteEvaluation(
        segments=route,
        departure=departure,
        achievable_driving_seconds=achievable_driving_seconds,
        expected_congestion_seconds=expected_congestion_seconds,
        expected_seconds=expected_seconds,
        delay_risk=delay_risk,
        total_distance_meters=total_distance,
        matching_observations=support,
    )


def initialise_worker(
    segment_samples: Dict[Segment, List[Sample]], trip_timezone: ZoneInfo, target_mph: float
) -> None:
    """Initialise one process with the shared read-only routing inputs."""
    global WORKER_SEGMENT_SAMPLES, WORKER_TRIP_TIMEZONE, WORKER_TARGET_MPH, WORKER_PREDICTION_CACHE, WORKER_DELAY_POINTS
    WORKER_SEGMENT_SAMPLES = segment_samples
    WORKER_TRIP_TIMEZONE = trip_timezone
    WORKER_TARGET_MPH = target_mph
    WORKER_PREDICTION_CACHE = {}
    WORKER_DELAY_POINTS = {
        segment: empirical_points(np.asarray([
            max(0.0, sample.traffic_seconds - sample.duration_seconds) for sample in samples
        ], dtype=float))
        for segment, samples in segment_samples.items()
    }


def evaluate_work_item(work_item: Tuple[Sequence[Segment], datetime]) -> RouteEvaluation:
    """Evaluate one route/departure pair inside a process-pool worker."""
    route, departure = work_item
    if WORKER_TRIP_TIMEZONE is None:
        raise RuntimeError("Router worker was not initialised")
    return evaluate_route(
        route,
        departure,
        WORKER_SEGMENT_SAMPLES,
        WORKER_TRIP_TIMEZONE,
        WORKER_TARGET_MPH,
        WORKER_PREDICTION_CACHE,
        WORKER_DELAY_POINTS,
    )


def bounded_evaluations(
    executor: ProcessPoolExecutor,
    work_items: Iterable[Tuple[Sequence[Segment], datetime]],
    max_pending: int,
) -> Iterable[RouteEvaluation]:
    """Keep only a small, bounded number of process-pool tasks in memory."""
    iterator = iter(work_items)
    pending = set()

    def submit_next() -> bool:
        try:
            pending.add(executor.submit(evaluate_work_item, next(iterator)))
            return True
        except StopIteration:
            return False

    for _ in range(max_pending):
        if not submit_next():
            break
    while pending:
        completed, pending = wait(pending, return_when=FIRST_COMPLETED)
        for future in completed:
            yield future.result()
            submit_next()


def candidate_routes(
    graph: nx.DiGraph, start: str, end: str, limit: int
) -> Iterable[List[Segment]]:
    try:
        node_paths = nx.shortest_simple_paths(graph, start, end, weight="weight")
        for nodes in islice(node_paths, limit):
            yield [graph[first][second]["segment"] for first, second in zip(nodes[:-1], nodes[1:])]
    except (nx.NetworkXNoPath, nx.NodeNotFound):
        return


def generated_departures(
    segment_samples: Dict[Segment, List[Sample]], trip_timezone: ZoneInfo
) -> List[datetime]:
    """Generate 30-minute departure patterns from all available month/weekday data.

    The prediction model uses month, weekday, and time of day; exact historic
    timestamps add no predictive detail. One representative date per available
    month/weekday pattern keeps the search comprehensive without re-scoring
    equivalent dates from different years.
    """
    representative_dates: Dict[Tuple[int, int], datetime] = {}
    for samples in segment_samples.values():
        for sample in samples:
            local = sample.collected_at.astimezone(trip_timezone)
            key = (local.month, local.weekday())
            if key not in representative_dates or local > representative_dates[key]:
                representative_dates[key] = local

    departures: List[datetime] = []
    for representative in representative_dates.values():
        midnight = representative.replace(hour=0, minute=0, second=0, microsecond=0)
        departures.extend(midnight + timedelta(minutes=30 * slot) for slot in range(48))
    return sorted(departures)


def route_label(route: Sequence[Segment]) -> str:
    return " -> ".join([route[0].start] + [segment.end for segment in route])


def rank(evaluations: Sequence[RouteEvaluation], profile: str, max_delay_risk: float) -> List[RouteEvaluation]:
    eligible = [item for item in evaluations if item.delay_risk <= max_delay_risk]
    pool = eligible or list(evaluations)
    if profile == "fastest":
        key = lambda item: (item.expected_seconds, item.delay_risk)
    elif profile == "reliability":
        key = lambda item: (item.delay_risk, item.expected_seconds)
    else:
        key = lambda item: (item.expected_seconds, item.delay_risk)
    return sorted(pool, key=key)


def format_eastern_departure(departure: datetime) -> str:
    """Render collected timestamps in New York local time, not collector time."""
    local = departure.astimezone(EASTERN_TIME)
    return local.strftime("%A, %m/%d/%y at %H:%M %Z (Eastern Time)")


def print_option(title: str, evaluation: RouteEvaluation, target_mph: float) -> None:
    print(f"\n=== {title} ===")
    print(f"Route:                 {route_label(evaluation.segments)}")
    print(f"Traffic pattern:       {format_eastern_departure(evaluation.departure)}")
    print(f"Target average speed:  {target_mph:.1f} mph")
    print(f"Achievable drive time: {format_duration(evaluation.achievable_driving_seconds)}")
    print(f"Expected congestion:   +{format_duration(evaluation.expected_congestion_seconds)}")
    print(f"Expected route time:   {format_duration(evaluation.expected_seconds)}")
    print(f"Traffic-delay risk:    {evaluation.delay_risk * 100:.1f}%")
    print(f"Matching observations: {evaluation.matching_observations}")
    print(f"Distance:              {meters_to_miles(evaluation.total_distance_meters):.1f} miles")


def main(argv: Optional[Sequence[str]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("start", help="Start node, e.g. redball")
    parser.add_argument("end", help="End node, e.g. portofino")
    parser.add_argument("speed_mph", type=float,
                        help="Target average driving speed, excluding traffic delays")
    parser.add_argument("--profile", choices=("balanced", "fastest", "reliability"), default="balanced")
    parser.add_argument("--timezone", default="America/New_York",
                        help="IANA timezone for traffic buckets (default: America/New_York)")
    parser.add_argument("--candidate-routes", type=int, default=25,
                        help="Maximum baseline-time-ranked routes to score (default: 25)")
    parser.add_argument("--workers", type=int, default=os.cpu_count() or 1,
                        help="Worker processes used for scoring (default: all available CPU cores)")
    parser.add_argument("--max-delay-risk", type=float, default=0.20,
                        help="Eligibility cap from 0 to 1 (default: 0.20)")
    args = parser.parse_args(argv)

    if args.speed_mph <= 0:
        parser.error("speed_mph must be positive")
    if args.candidate_routes < 1 or args.workers < 1:
        parser.error("--candidate-routes and --workers must be positive")
    if not 0.0 <= args.max_delay_risk <= 1.0:
        parser.error("--max-delay-risk must be between 0 and 1")
    try:
        trip_timezone = ZoneInfo(args.timezone)
    except Exception as error:
        parser.error(f"Invalid IANA timezone: {error}")

    print("CannonMiner time-aware router")
    print("Loading traffic observations...")
    segment_samples = load_segment_samples()
    graph = build_graph(segment_samples, args.speed_mph)
    routes = list(candidate_routes(graph, args.start, args.end, args.candidate_routes))
    if not routes:
        print(f"No route found from '{args.start}' to '{args.end}'.")
        return 2

    departures = generated_departures(segment_samples, trip_timezone)
    work_item_count = len(routes) * len(departures)
    print(f"Scoring {len(routes)} routes across {len(departures)} generated departure patterns at {args.speed_mph:.1f} mph...")
    worker_count = min(args.workers, work_item_count)
    with ProcessPoolExecutor(
        max_workers=worker_count,
        initializer=initialise_worker,
        initargs=(segment_samples, trip_timezone, args.speed_mph),
    ) as executor:
        evaluations = list(tqdm(
            bounded_evaluations(
                executor,
                ((route, departure) for route in routes for departure in departures),
                max_pending=worker_count * 4,
            ),
            total=work_item_count,
            desc=f"Scoring with {worker_count} workers",
            unit="pair",
        ))

    ordered = rank(evaluations, args.profile, args.max_delay_risk)
    if not any(item.delay_risk <= args.max_delay_risk for item in evaluations):
        print("Warning: no option meets the requested delay-risk requirement; showing the best available options.")
    print_option(f"Recommended ({args.profile})", ordered[0], args.speed_mph)
    for position, evaluation in enumerate(ordered[1:3], start=2):
        print_option(f"Alternative {position}", evaluation, args.speed_mph)
    print("\nTraffic-delay risk combines nontrivial segment slowdowns with material total-route congestion.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
