## CannonBall Trail+

**CannonBall Trail+** is a saved, real-time route simulation built on
CannonMiner's enabled segment graph and collected measurements. Start a run with
a departure time, vehicle fuel economy and capacity, target speed, and up to two
randomly generated teammates. The signed-in user is always part of the roster.
Realtime mode advances one simulated minute per real minute; arcade mode
advances one simulated hour per real minute.

Simulation setup uses a fixed car catalog rather than free-form vehicle values.
Each car stores three speed-dependent MPG ratings, fuel capacity, stock and
tuned top speeds, and a maximum fuel-cell count reserved for a later upgrade
system. Users may request a target above the selected car's current top speed,
but actual speed is capped and the live map shows a vehicle-limit status icon.
Traffic and weather caps take priority when they impose a lower limit. Cars use
their stock top speed until a future equipped item enables the tuned top speed.

The setup car builder supports up to the selected car's maximum fuel-cell count.
Each $300 cell adds 20 gallons of capacity. A $1,500 cruising tune adds 5 MPG
above 70 mph and enables the tuned top speed. $1,500 cruising tires add 1 MPG
and halve the configured flat-tire chance. Radio Scanner ($250), Radar Scanner
($600), and Radar Jammer ($1,000) can be equipped together. The Radar Scanner
first reduces encounter probability; when a jammer is also installed it handles
an encounter before the Radio Scanner. A Radar Jammer cannot be installed
without a Radar Scanner. The live instrument panel represents installed
equipment with symbols whose tooltips describe each modifier. The
builder displays equipment modifiers and total cost without revealing base MPG.
The Radio Scanner has an equal chance to produce an early slowdown/pass or
continue to the normal police result. The Radar Scanner halves the configured
police-event chance. The Radar Jammer yields a 25% slowdown/pass, 25% normal
encounter, and 50% bypass; being pulled over with it always ends in jail.

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

Fuel stops use a 60-second payment step. Fuel flows at a base rate of 5 GPM
and scales linearly toward 10 GPM as the crew's combined co-piloting skill
approaches 150; totals of 150 or higher receive the full 10 GPM rate. Vehicle
deceleration and acceleration continue to occur at 5 mph per second.

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
jail. Each segment has three independently rolled event windows,
spread across 15% to 90% of the segment, so more than one random event can occur
during the same segment. Recorded traffic also begins at a deterministic
interior point, and its cap lasts only long enough to reproduce the observed
delay. Tail winds improve fuel economy by
15% and head winds reduce it by 15% for the remainder of the segment. Co-pilot
and driving-skill effectiveness degrade progressively above 50% fatigue. The
crew display shows each affected skill's current effective value and reduction.
Superadmins can configure the baseline percentage for weather, flat tires,
headwinds, tailwinds, police, and road events. Regional and seasonal eligibility
still determines which weather and wind effects can occur. When more than one
effect succeeds at the same event opportunity, the seeded simulator selects one
deterministically. These percentages are captured when a simulation is created,
so later settings changes do not alter a saved game. Traffic is not governed by these percentages: past runs use
recorded traffic evidence and future runs use supported predicted evidence.
Every segment has an independent 1% base crash chance at any speed. This base
chance does not display a speed warning. A target speed over 120 mph activates the configurable high-speed crash check;
speeds over 145 mph also activate a separate higher-speed crash check. Either
crash immediately ends the run. Crossing each threshold presents its own
warning and pauses simulated time until the warning is acknowledged. Lowering
the effective vehicle speed below a threshold rearms that warning for the next
upward crossing.
