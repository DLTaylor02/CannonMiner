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
50%. If no eligible replacement is available, the crew can rest on the side of
the road. The vehicle remains stopped while the simulation clock and stopped
time continue advancing, and the driver picker updates as crew members recover.
The crew may remain stopped after a driver becomes eligible. Drivers may also
cover co-pilot duties during solo runs or when every
teammate requires rest, at twice the normal driving fatigue rate. Runs can be
paused and resumed without keeping the browser open.

The live map reuses overview polylines already stored by the collector and does
not make additional Google requests. Untraveled geometry is gray. Traveled
geometry is colored from red at zero speed to green at the target speed, and
the final summary joins the stored polylines for every segment traveled. Fuel
stops, driver changes, police encounters, flat tires, road obstacles, and each
weather condition are retained as distinct symbols on both maps.

Observed traffic delay becomes an evidence-derived speed cap of 65 mph or less
for the affected segment. Weather is independent of crew skill and applies a
speed cap: fog 45 mph, rain 80 mph, ice 35 mph, or snow 65 mph. Each weather
event lasts for an undisclosed, internally bounded travel distance and carries
into the next route segment when necessary. The simulator pauses with a notice
when conditions return to normal. Weather selection uses the simulation month
and the active segment's endpoints: snow and ice are restricted to winter in
northern or mountain regions, fog favors eastern, plains, and coastal routes,
and wind events favor plains, mountain, and western routes. Road
events can add a bounded delay, with driving and co-pilot skill reducing their
impact. A flat tire includes controlled deceleration and acceleration around a
10-to-15-minute repair. Repair time uses the entire crew's average effective
co-piloting skill at the time of the flat, including fatigue reductions. Police
events occur only above 70 mph; a stop has an equal
chance of adding 30 minutes or ending the run because the crew was taken to
jail. Driver fatigue at or above 75% increases the likelihood of non-weather
obstacle events. Each segment has three independently rolled event windows,
spread across 15% to 90% of the segment, so more than one random event can occur
during the same segment. Recorded traffic also begins at a deterministic
interior point, and its cap lasts only long enough to reproduce the observed
delay. Tail winds improve fuel economy by
15% and head winds reduce it by 15% for the remainder of the segment. Co-pilot
and driving-skill effectiveness degrade progressively above 50% fatigue. The
crew display shows each affected skill's current effective value and reduction.
Ordinary random events occur more often than in the initial simulator balance.
A target speed above 119 mph activates one additional deterministic
crash risk on each segment; a crash immediately ends the run.
