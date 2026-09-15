## CannonBall Trail+

**CannonBall Trail+** is a saved, real-time route simulation built on
CannonMiner's enabled segment graph and collected measurements. Start a run with
a departure time, vehicle fuel economy and capacity, target speed, and up to two
randomly generated teammates. The signed-in user is always part of the roster.
Realtime mode advances one simulated minute per real minute; arcade mode
advances one simulated hour per real minute.

Past departures require a directly recorded observation near the time the
vehicle enters each segment. Future departures use only directly supported
month, weekday, and local-time evidence. The simulator does not call Google,
create a Run Window, alter historical analysis, or invent values for unsupported
traffic conditions.

The simulator worker advances the saved clock and writes structured events for
segment changes, delays, obstacles, fatigue, fuel stops, and completion. Branch
choices appear only where the route graph has multiple valid paths toward
Portofino. Driver and co-pilot fatigue rises according to role and endurance;
rest becomes more effective over time. Changing the driver requires stopping
the vehicle. A driver reaching 100% fatigue is locked into rest until reaching
50%. Drivers may also cover co-pilot duties during solo runs or when every
teammate requires rest, at twice the normal driving fatigue rate. Runs can be
paused and resumed without keeping the browser open.

Observed traffic delay becomes an evidence-derived speed cap of 65 mph or less
for the affected segment. Weather is independent of crew skill and applies a
segment speed cap: fog 45 mph, rain 80 mph, ice 35 mph, or snow 65 mph. Road
events can add a bounded delay, with driving and co-pilot skill reducing their
impact. A flat tire includes controlled deceleration and acceleration around a
15-minute repair. Police events occur only above 70 mph; a stop has an equal
chance of adding 30 minutes or ending the run because the crew was taken to
jail. Driver fatigue at or above 75% increases the likelihood of non-weather
obstacle events.
