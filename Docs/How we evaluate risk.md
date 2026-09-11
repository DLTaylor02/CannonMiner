The risk score answers:

> “Based on historical traffic samples, what percentage of simulated trips experience a meaningful slowdown?”

For every route and departure time:

1. We find historical traffic observations near that weekday and time, primarily within about 90 minutes.
2. Samples from the same or adjacent months receive more weight.
3. Sparse time-specific data is blended with the segment’s overall history.
4. We simulate the trip **1,024 times**, randomly drawing plausible delays for every segment.
5. A simulation is considered risky when either:
   - Any segment has at least **2 minutes of delay**, or **5% of its normal duration**, whichever is greater.
   - Total route delay reaches at least **5 minutes**, or **2% of the target-speed driving time**, whichever is greater.
6. The risk score is the percentage of simulations meeting either condition.

For example, if 205 of 1,024 simulated trips encounter a meaningful slowdown, the displayed risk is approximately **20.0%**.

The configured maximum risk does **not** change this calculation. It filters the results: CannonMiner prefers options below that limit. If none qualify, it returns the best available options anyway.

One important detail: under the **Reliability** strategy, routes are ranked by risk first and expected time second. Both **Balanced** and **Fastest** currently rank by expected time first and use risk only as the tie-breaker. So at present, Balanced and Fastest effectively behave the same.