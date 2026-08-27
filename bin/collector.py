# bin/collector.py
import requests
import time

# Fallback if URL is not defined in maps_key
BASE_URL = GOOGLE_MAPS_BASE_URL if 'GOOGLE_MAPS_BASE_URL' in globals() else "https://maps.googleapis.com/maps/api/directions/json"

class Collector:
    def __init__(self, api_key=None):
        self.api_key = api_key or GOOGLE_API_KEY

    def get_travel_time(self, origin: str, destination: str, mode="driving"):
        """
        Fetches travel time (with traffic) from Google Directions API.
        Returns a dict: duration, duration_in_traffic, distance, raw payload.
        """
        params = {
            "origin": origin,
            "destination": destination,
            "mode": mode,
            "departure_time": int(time.time()),
            "key": self.api_key,
        }

        resp = requests.get(BASE_URL, params=params, timeout=15)
        resp.raise_for_status()
        payload = resp.json()

        if payload.get("status") != "OK":
            raise RuntimeError(f"Google Maps API error: {payload}")

        leg = payload["routes"][0]["legs"][0]

        return {
            "duration": leg["duration"]["value"],
            "duration_in_traffic": leg.get("duration_in_traffic", {}).get("value"),
            "distance": leg["distance"]["value"],
            "raw": payload,
        }
