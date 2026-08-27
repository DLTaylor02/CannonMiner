#!/usr/bin/env python3
"""
router.py

Created by OpenAI ChatGPT Plus and Dean Taylor 2025

Usage:
  python router.py start end speed_mph
"""

from __future__ import annotations

import sys
import math
import argparse
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Dict, List, Tuple, Optional
from zoneinfo import ZoneInfo

import numpy as np
import networkx as nx
from tqdm import tqdm
from pydantic import BaseModel, Field
from tenacity import retry, stop_after_attempt, wait_exponential_jitter

from psycopg import connect
from psycopg.rows import dict_row

from db_config import DB_CONN_STRING


# ============================================================
# Models
# ============================================================

class SegmentSample(BaseModel):
    collected_at: datetime
    duration_seconds: float = Field(ge=0)
    duration_in_traffic_seconds: float = Field(ge=0)
    distance_meters: float = Field(gt=0)


@dataclass(frozen=True)
class SegmentKey:
    table: str
    start: str
    end: str


@dataclass
class SegmentStats:
    count: int
    distance_meters_median: float
    traffic_mean_s: float
    traffic_std_s: float
    traffic_cv: float
    reliability_0_1: float


@dataclass
class RouteEval:
    segments: List[SegmentKey]
    supporting_samples_total: int
    total_distance_meters: float
    predicted_travel_seconds: float
    best_departure: datetime
    confidence_0_1: float
    route_reliability_0_1: float
    route_alignment_0_1: float


# ============================================================
# Helpers
# ============================================================

METER_PER_MILE = 1609.344

def meters_to_miles(m: float) -> float:
    return m / METER_PER_MILE


def mph_to_mps(mph: float) -> float:
    return (mph * METER_PER_MILE) / 3600.0


def format_hhmmss(seconds: float) -> str:
    s = int(round(seconds))
    h = s // 3600
    m = (s % 3600) // 60
    s = s % 60
    return f"{h:02d}:{m:02d}:{s:02d}"


def ensure_utc(dt: datetime) -> datetime:
    if dt.tzinfo is None:
        return dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)
    
def format_seconds_readable(seconds: float) -> str:
    seconds = int(round(seconds))
    m, s = divmod(seconds, 60)
    h, m = divmod(m, 60)
    if h > 0:
        return f"{h:02d}:{m:02d}:{s:02d}"
    return f"{m:02d}:{s:02d}"
    
EST = ZoneInfo("America/New_York")

def to_est(dt: datetime) -> datetime:
    if dt.tzinfo is None:
        # assume UTC if naive
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(EST)

# ============================================================
# DB Access (psycopg3 safe)
# ============================================================

@retry(stop=stop_after_attempt(5), wait=wait_exponential_jitter(0.5, 5))
def db_fetchall(query: str, params: Tuple = ()) -> List[dict]:
    with connect(DB_CONN_STRING, row_factory=dict_row) as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchall()


def list_segment_tables() -> List[str]:
    q = """
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND table_type = 'BASE TABLE'
      AND (
            LEFT(table_name, 8) = 'segment_'
         OR LEFT(table_name, 16) = 'segmentestimate_'
      )
    ORDER BY table_name;
    """
    rows = db_fetchall(q)
    return [r["table_name"] for r in rows]


def load_samples(table: str) -> List[SegmentSample]:
    q = f"""
    SELECT collected_at, duration_seconds, duration_in_traffic_seconds, distance_meters
    FROM public.{table}
    WHERE collected_at IS NOT NULL
      AND duration_seconds IS NOT NULL
      AND duration_in_traffic_seconds IS NOT NULL
      AND distance_meters IS NOT NULL
    ORDER BY collected_at;
    """
    rows = db_fetchall(q)
    out: List[SegmentSample] = []
    for r in rows:
        try:
            out.append(
                SegmentSample(
                    collected_at=ensure_utc(r["collected_at"]),
                    duration_seconds=float(r["duration_seconds"]),
                    duration_in_traffic_seconds=float(r["duration_in_traffic_seconds"]),
                    distance_meters=float(r["distance_meters"]),
                )
            )
        except Exception:
            pass
    return out


# ============================================================
# Graph / Stats
# ============================================================

def parse_segment_name(table: str) -> Optional[SegmentKey]:
    if table.startswith("segmentestimate_"):
        rest = table[len("segmentestimate_") :]
    elif table.startswith("segment_"):
        rest = table[len("segment_") :]
    else:
        return None

    if "_to_" not in rest:
        return None

    start, end = rest.split("_to_", 1)
    return SegmentKey(table=table, start=start, end=end)


def compute_stats(samples: List[SegmentSample]) -> SegmentStats:
    if not samples:
        return SegmentStats(0, float("nan"), float("nan"), float("nan"), float("inf"), 0.0)

    traffic = np.array([s.duration_in_traffic_seconds for s in samples])
    dist = np.array([s.distance_meters for s in samples])

    mean = float(np.mean(traffic))
    std = float(np.std(traffic))
    cv = std / mean if mean > 0 else float("inf")
    reliability = 1.0 / (1.0 + cv)

    return SegmentStats(
        count=len(samples),
        distance_meters_median=float(np.median(dist)),
        traffic_mean_s=mean,
        traffic_std_s=std,
        traffic_cv=cv,
        reliability_0_1=float(np.clip(reliability, 0.0, 1.0)),
    )


def build_graph(segments: List[SegmentKey], stats: Dict[str, SegmentStats]) -> nx.DiGraph:
    G = nx.DiGraph()
    for s in segments:
        st = stats.get(s.table)
        if not st or not math.isfinite(st.distance_meters_median):
            continue
        G.add_edge(s.start, s.end, table=s.table, weight=st.distance_meters_median)
    return G


# ============================================================
# Routing / Evaluation
# ============================================================

def predicted_seconds(dist_m: float, mph: float) -> float:
    return dist_m / mph_to_mps(mph)


def first_ge(times: List[datetime], t: datetime) -> int:
    lo, hi = 0, len(times)
    while lo < hi:
        mid = (lo + hi) // 2
        if times[mid] < t:
            lo = mid + 1
        else:
            hi = mid
    return lo


def chain_alignment(
    route: List[SegmentKey],
    samples_by_table: Dict[str, List[SegmentSample]],
    stats: Dict[str, SegmentStats],
    speed_mph: float,
    departure: datetime,
) -> Optional[float]:

    t = departure
    gaps: List[float] = []

    for i, seg in enumerate(route):
        samples = samples_by_table[seg.table]
        times = [s.collected_at for s in samples]
        dist = stats[seg.table].distance_meters_median

        if i > 0:
            idx = first_ge(times, t)
            if idx >= len(times):
                return None
            gap = (times[idx] - t).total_seconds()
            gaps.append(max(0.0, gap))
            t = times[idx]

        t += timedelta(seconds=predicted_seconds(dist, speed_mph))

    if not gaps:
        return 1.0
    return float(np.mean([math.exp(-g / 3600.0) for g in gaps]))


def evaluate_route_best_departure(
    route: List[SegmentKey],
    samples_by_table: Dict[str, List[SegmentSample]],
    stats: Dict[str, SegmentStats],
    speed_mph: float,
) -> Optional[RouteEval]:

    first_samples = samples_by_table.get(route[0].table, [])
    if not first_samples:
        return None

    departures = [s.collected_at for s in first_samples]

    weights = []
    reliabilities = []
    for seg in route:
        st = stats[seg.table]
        weights.append(st.distance_meters_median)
        reliabilities.append(st.reliability_0_1)

    route_reliability = float(np.average(reliabilities, weights=weights))
    total_dist = sum(weights)
    predicted_total = sum(predicted_seconds(d, speed_mph) for d in weights)
    supporting = sum(stats[s.table].count for s in route)

    best: Optional[RouteEval] = None

    for depart in tqdm(
        departures,
        desc="  Departures",
        leave=False,
        unit="departure",
    ):
        alignment = chain_alignment(route, samples_by_table, stats, speed_mph, depart)
        if alignment is None:
            continue

        confidence = 0.85 * route_reliability + 0.15 * alignment

        ev = RouteEval(
            segments=route,
            supporting_samples_total=supporting,
            total_distance_meters=total_dist,
            predicted_travel_seconds=predicted_total,
            best_departure=depart,
            confidence_0_1=confidence,
            route_reliability_0_1=route_reliability,
            route_alignment_0_1=alignment,
        )

        if not best or ev.confidence_0_1 > best.confidence_0_1:
            best = ev

    return best


def route_str(route: List[SegmentKey]) -> str:
    return " -> ".join(s.table for s in route)


def pick_min_distance_route(G: nx.DiGraph, start: str, end: str) -> Optional[List[SegmentKey]]:
    try:
        nodes = nx.shortest_path(G, start, end, weight="weight")
    except Exception:
        return None
    return [SegmentKey(G[u][v]["table"], u, v) for u, v in zip(nodes[:-1], nodes[1:])]


# ============================================================
# Main
# ============================================================

print("CannonMiner Router v1.1")

def main(argv: List[str]) -> int:
    p = argparse.ArgumentParser()
    p.add_argument("start")
    p.add_argument("end")
    p.add_argument("speed", type=float)
    args = p.parse_args(argv)

    print("-" * 40)
    print("Loading segments...")

    tables = list_segment_tables()
    segments = [parse_segment_name(t) for t in tables if parse_segment_name(t)]

    samples: Dict[str, List[SegmentSample]] = {}
    stats: Dict[str, SegmentStats] = {}

    for s in tqdm(segments, desc="Loading table samples", unit="segments"):
        samples[s.table] = load_samples(s.table)
        stats[s.table] = compute_stats(samples[s.table])

    print("Building graph...")
    G = build_graph(segments, stats)

    print("Enumerating candidate routes...")
    routes = list(nx.all_simple_paths(G, args.start, args.end))
    print(f"Total routes compared: {len(routes)}")

    print("Scoring routes...")
    best: Optional[RouteEval] = None

    for nodes in tqdm(routes, desc="Evaluating routes", unit="route"):
        route = [SegmentKey(G[u][v]["table"], u, v) for u, v in zip(nodes[:-1], nodes[1:])]
        ev = evaluate_route_best_departure(route, samples, stats, args.speed)
        if ev and (not best or ev.confidence_0_1 > best.confidence_0_1):
            best = ev

    min_route = pick_min_distance_route(G, args.start, args.end)

    print()
    print("=== Best Route ===")
    if best:
        print(route_str(best.segments))
        print(f"Supporting samples: {best.supporting_samples_total}")
        print("\nSegment reliability breakdown:")
        for seg in best.segments:
            st = stats[seg.table]
            print(f"- {seg.table}")
            #print(f"    samples: {st.count}")
            #print(f"    mean traffic: {format_seconds_readable(st.traffic_mean_s)}")
            print(f"    std dev:      {format_seconds_readable(st.traffic_std_s)}")
            #print(f"    CV:           {st.traffic_cv:.3f}")
            print(f"    reliability:  {st.reliability_0_1 * 100:.1f}%")
        print(f"Total distance: {meters_to_miles(best.total_distance_meters):.2f} miles")
        print(f"Route confidence: {best.confidence_0_1 * 100:.2f}%")
        best_departure_est = to_est(best.best_departure)
        print(f"Best departure (EST): {best_departure_est.isoformat()}")
        total_std_dev_s = sum(
            stats[s.table].traffic_std_s
            for s in best.segments
        )
        base_time_s = best.predicted_travel_seconds
        with_traffic_s = base_time_s + total_std_dev_s

        print(f"Predicted total travel time at {args.speed:.1f} mph:")
        print(f"  {format_hhmmss(base_time_s)} (without traffic)")
        print(f"  {format_hhmmss(with_traffic_s)} (with traffic variability + {format_hhmmss(total_std_dev_s)})")
    else:
        print("No feasible route found.")

    print()
    print("=== Minimal Distance Route (pure distance) ===")
    if min_route:
        dist = sum(stats[s.table].distance_meters_median for s in min_route)
        dur = sum(predicted_seconds(stats[s.table].distance_meters_median, args.speed) for s in min_route)
        support = sum(stats[s.table].count for s in min_route)
        print(route_str(min_route))
        print(f"Supporting samples: {support}")
        print(f"Total distance: {meters_to_miles(dist):.2f} miles")
        print("Route confidence: N/A")
        print(f"Distance-based predicted duration: {format_hhmmss(dur)}")
    else:
        print("No distance-based route found.")

    print("\nDone.")
    print("-" * 40)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
