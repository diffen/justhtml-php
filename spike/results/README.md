# Spike benchmark results

A completed benchmark run ends with a JSON object whose `summary.complete` value
is `true`. The harness writes to `*.partial` while workers are running and only
renames the file to `*.jsonl` after every configured case completes.

`lead-20260718-053928.jsonl` predates that completion marker and is an interrupted
exploratory run, not Milestone 1 gate evidence. It stops during the `body *`
case and does not include the absent-selector case. Do not use it for a go/no-go
decision.

The completed measurements used for the design decision are:

- `lead-20260718-062311.jsonl` (36/36 rows)
- `cc-20260718-062533.jsonl` (2,800/2,800 rows; 100 documents)

Their interpretation, hashes, methodology, and the archival Git tag containing
the lexical implementation are recorded in
`docs/spike-streaming-select/report.md`.
