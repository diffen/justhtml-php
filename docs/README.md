# Design records

This directory contains maintainer-facing design history. For user guidance on
which parsing API to choose, start with the
[task-based parsing guide](../Streaming.md).

The shipped product has two normal choices: use the full parser by default, or
use `Stream::select()` when a crawler needs one or a few known elements and can
benefit from stopping early. For its supported selectors, targeted extraction
returns the same selected elements and content as a normal query. The faster
experimental alternative was rejected because it could silently return
different content on malformed pages.

## Streaming CSS selectors

- [Proposal and completed milestones](proposal-streaming-select.md)
- [Spike report and A-versus-B decision](spike-streaming-select/report.md)
- [Ordered yield frontier](spike-streaming-select/frontier.md)
- [Pruning-safety taxonomy](spike-streaming-select/pruning-taxonomy.md)

The report defines what a design spike is, records the measured performance
ceiling of the rejected approach, and explains why only approach A remains in
maintained source. The complete experiment is preserved at Git tag
`stream-select-spike-2026-07-18`.
