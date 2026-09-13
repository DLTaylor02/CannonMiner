The risk score answers:

> "Based on historical traffic samples, what percentage of simulated trips experience a meaningful slowdown?"

For every route and departure time:

1. We find historical traffic observations near that weekday and time, primarily within about 90 minutes.
2. Samples from the same or adjacent months receive more weight.
3. Current calculations require direct observations for every segment and do not replace missing time-specific data with the segment-wide history.
4. We simulate the trip **1,024 times**, randomly drawing plausible delays for every segment.
5. A simulation is considered risky when either:
   - Any segment has at least **2 minutes of delay**, or **5% of its normal duration**, whichever is greater.
   - Total route delay reaches at least **5 minutes**, or **2% of the target-speed driving time**, whichever is greater.
6. The empirical risk is the percentage of simulations meeting either condition.

The displayed score uses the upper bound of a one-sided 95% Wilson confidence interval based on the weakest-supported segment. This prevents a small set of nearby observations with no delay events from being reported as proof of exactly 0% risk. It adds uncertainty, not assumed traffic measurements, and approaches the simulated rate as observation coverage grows.

For example, if 205 of 1,024 simulated trips encounter a meaningful slowdown, the empirical risk is approximately **20.0%**. The displayed conservative risk can be higher when the weakest segment has limited observations.

The configured maximum risk does **not** change this calculation. It filters the results: CannonMiner prefers options below that limit. If none qualify, it returns the best available options anyway.

One important detail: under the **Reliability** strategy, routes are ranked by risk first and expected time second. Both **Balanced** and **Fastest** currently rank by expected time first and use risk only as the tie-breaker. So at present, Balanced and Fastest effectively behave the same.

## Calculation method 3

Current calculations require every segment to have direct observations from the same weekday and within approximately 90 minutes of the predicted segment arrival. Candidates with an unsupported segment are not evaluated, and their missing data is not replaced with the segment-wide average.

CannonMiner retains a larger set of the strongest supported candidates. The first result is always the numerically best result for the selected strategy. Up to two remaining results must be within 15 expected minutes of the winner and are chosen to expose competitive alternatives on different dates, routes, or departure windows instead of returning only nearly identical times.

Selection confidence is calculated by repeatedly resampling the simulated outcomes of the retained candidates. It is the percentage of those trials in which a candidate wins. This measures recommendation stability; repeated automated jobs are not treated as independent evidence.

The Calendar converts that confidence into risk-adjusted points using `selection confidence x (1 - conservative delay risk)`. It adds contributions from the latest calculation for each distinct configuration. The hover details show the adjusted points, accumulated confidence points, and confidence-weighted conservative risk separately.

Calculated runs record their calculation-method version. Aggregate pages display only the current version, while an older result remains available through its direct analysis URL.
