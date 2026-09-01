## Upgrading an original Python installation

No special action on your part is needed. The installer will handle it.

Back up PostgreSQL before upgrading. Install this version over the original
project and run `bash setup.sh` on the same host. When the original
`cannonminer` database exists, the installer reuses it and grants the new
application role access. It imports original observation tables:

- `segment_<start>_to_<end>` observations

Legacy `segmentestimate_*` and other estimation tables are intentionally
ignored.

Imports are tracked by source table and row ID, so rerunning the installer does
not duplicate migrated measurements. Original tables are left intact and can
be retained until the migrated counts have been verified. Custom legacy tables
are imported when their `<start>_to_<end>` name matches a row in `segments`;
add custom segment rows before rerunning `bash setup.sh`.

During this stage setup prints a row-based progress bar, the active legacy
table, and a final imported/skipped summary. Imports are committed in batches,
so a long migration can be resumed by rerunning setup without duplicating
completed rows.

## Router parity

`router.py` remains the behavioral reference for traffic weighting, empirical
quantiles, arrival propagation, risk thresholds, simulation count, eligibility,
and ranking. The PHP implementation retains raw delay distributions in compact
packed buffers rather than replacing them with averages. NumPy's seeded PCG64
stream is specific to NumPy; until the cross-language parity fixture confirms
the same recommendation and ordering, small Monte Carlo risk differences must
be treated as possible rather than silently described as exact parity.