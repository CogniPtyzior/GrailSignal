# Thinking

## Approach

- I treated human review, conflicts, and cannot-verify outcomes as valid business results.
- I optimized for precision over recall instead of maximizing contact coverage.
- I kept the implementation focused on a small batch workflow with explicit provenance.

## Delivery Process

- Step-by-step delivery kept each design change small and coherent.
- Runner checks were used after meaningful changes.
- Review-driven hardening folded edge cases back into the design.

## Architecture

- The implementation uses a lightweight hexagonal structure.
- Domain and application code are independent from CLI, CSV, JSON, and filesystem details.
- Ports isolate input reading, source lookup, result writing, and suppression.
- Infrastructure adapters handle CSV, mocks, JSON output, execution logs, and suppression configuration.
- The CLI runner composes the slice explicitly without introducing a framework.

## Specifications Alignment

- The batch only uses mocked providers from `Mocks/`.
- No real API call or scraping path is implemented.
- The confidence threshold is configured at `70`.
- Low-confidence, conflicting, suppressed, or unverifiable rows emit no contact channel.
- Target contacts follow the requested priority: accounting, business owner, finance lead, then office manager.
- Personal email domains are filtered out of business contact output.
- Each emitted contact value keeps field-level mock-source provenance.

## Quality

- Known failures use typed exceptions, concise stderr output, and execution-log details.
- Each run writes timestamped result and log files to avoid accidental overwrites.
- The core behavior is deterministic, making output changes easy to inspect.
- Company-to-source matching uses exact lookup first, then canonical-name matching with ambiguity detection.
- Streaming output and checkpoint files are available for larger local batches.

## Tradeoffs

- The scoring model is deterministic and explainable, but still heuristic.
- The personal-email domain list is pragmatic, not exhaustive.
- The output keeps detailed review state in `source` instead of adding another public field.
- No database, queue, HTTP API, or UI was added because the demo is a CLI batch.

## Next Improvements

- Externalize scoring weights if more calibration data becomes available.
- Move personal-email domain policy to configuration if the list grows.
- Add `review_state` to the public output if the contract expands.
- Add richer fixtures for accounting, finance lead, and office-manager cases.
- Switch logs to structured JSON if the batch becomes operational.






