# Grail Signal Architecture

## Goal

Grail Signal is a compact batch application that reads company rows, queries local mocked contact sources, and writes one
traceable result per input company. It follows `SPECIFICATIONS.md`: mocked sources only, precision over recall,
confidence threshold `70`, field-level provenance, opt-out support, and explicit human-review states.

The demo is intentionally scoped to contact finding. It does not include a CRM, database, queue, HTTP API, crawler, or
real third-party integration.

## Structure

The implementation uses a lightweight hexagonal architecture under `ContactFinder/`:

- `Domain/`: immutable input, evidence, merged evidence, public result, and review-state objects.
- `Application/`: batch orchestration, evidence merge, confidence scoring, review gating, and suppression gating.
- `Ports/`: contracts for input reading, source lookup, result writing, and suppression checks.
- `Infrastructure/`: CSV reader, mock-source provider, JSON/JSONL writers, checkpoint writer, execution-log writer, and suppression adapters.
- `Tests/`: PHPUnit unit, integration, and functional tests.

The public entry point is:

```bash
php run_contact_finder.php
```

The CLI composes concrete adapters manually. There is no framework container.

## Processing Flow

1. `CsvCompanyInputReader` streams `company_name` and `mailing_address` from the configured CSV.
2. `MockSourceProvider` resolves company names with exact lookup first, then safe canonical-name matching.
3. `MockSourceProvider` accepts only the three supported mock providers: `business_registry`, `business_directory`, and `contact_signal`.
4. `ContactMerger` selects deterministic identity/channel fields and keeps selected-field provenance.
5. `ConfidenceScorer` computes an explainable confidence value from source diversity, agreement, selected-channel confidence, role quality, payment inbox quality, and risk signals.
6. `ReviewPolicy` applies the threshold and conflict rules, and clears `contact_email_or_phone` when the row is not usable.
7. `FindContacts` applies final suppression against the merged candidate channels before returning the public result.
8. `JsonResultWriter` writes JSON results, or `JsonLinesResultWriter` streams one JSON object per line in JSONL mode.
9. `ExecutionLogWriter` writes run metrics; `CheckpointWriter` records row progress in JSONL mode.

Known infrastructure and configuration failures use Contact Finder exceptions. The runner appends failures to the same
execution log when one has already been opened, then prints a concise stderr message with the log path.

## Domain Invariants

The domain layer protects the public output shape:

- `ContactResult` requires a non-empty company name and source provenance.
- `confidence_score` must be between `0` and `100`.
- reviewed rows must not expose `contact_email_or_phone`.
- usable rows must expose a contact channel.
- `needs_human_review` must match the internal review state.
- the `source` field must include the matching `decision=...` marker.

These invariants make it harder for adapters or future orchestration changes to emit an incoherent public row.

## Scoring And Review

The scoring model is deterministic and explainable rather than statistical. It rewards corroborated evidence and target
roles, but caps or penalizes risky cases.

Important rules:

- evidence absence returns `cannot_verify` and score `0`;
- conflicts force review;
- registered-agent-only evidence is not usable;
- invalid or personal email domains are ignored for business-contact output;
- provider confidence is used only when attached to the selected email or phone channel;
- phone-only contacts are capped below the threshold unless the phone is corroborated;
- strong payment inboxes (`billing@`, `accounting@`, `ap@`) can pass when selected-channel confidence is high enough;
- weak single-source guesses stay below the threshold.

`ReviewPolicy` applies the configured confidence threshold, currently `70`. Rows below the threshold keep
`contact_email_or_phone` empty and set `needs_human_review` to `true`.

## Suppression

Suppression is represented by a port because opt-out handling is a compliance boundary.

- `AllowAllSuppressionList` keeps the default run simple.
- `JsonSuppressionList` can suppress companies or channels when `suppression_list_path` is configured.
- `FindContacts` checks suppression after merge/review using the merged candidate email and phone, so a channel opt-out
  can still purge identity data even when the public channel was already hidden by review gating.

Suppressed rows are routed to review, clear `contact_name`, `contact_role`, and `contact_email_or_phone`, set score `0`,
and append `suppression=opt_out` to provenance.

## Output And Observability

The default output format is pretty JSON. JSONL mode is available through `output_format = 'jsonl'` for streaming output.

Generated files are written below `Storage/ContactFinder/`:

- `Results/`: timestamped JSON or JSONL result files;
- `Logs/`: execution summaries and row progress in streaming mode;
- `Checkpoints/`: lightweight JSON checkpoints in JSONL mode.

The checkpoint records the run id, processed row count, output path, log path, and timestamp. It is observability and
partial-output support, not a full automatic resume system.

## Tests

The project uses PHPUnit through Composer:

```bash
composer test
```

The suite covers:

- domain object invariants;
- merge decisions and provenance;
- scoring caps and payment-inbox handling;
- review policy behavior;
- suppression behavior;
- CSV input parsing;
- mock fixture validation and provider allowlisting;
- JSON writers and execution logs;
- CLI success and known-failure paths.

## Design Decisions

The architecture is intentionally light. Ports exist where IO or policy boundaries matter, but the code avoids factories,
framework adapters, background jobs, persistence, or runtime service discovery.

CSV validation is split by responsibility:

- domain objects reject missing or excessively long values;
- the CSV adapter adds row-level diagnostics;
- strict postal-format validation is avoided so imperfect but useful rows can still be ingested.

Mock-source matching is conservative: exact company-name lookup first, then canonical-name matching with ambiguity
detection. There is no broad fuzzy matching.

## Strengths

- Precision-oriented behavior: weak, conflicting, generic, or absent evidence is routed to human review.
- Traceability: emitted fields carry `mock://...` provenance.
- Compliance gates: personal emails, opt-outs, unsupported providers, conflicts, and registered-agent-only evidence are blocked from usable output.
- Low coupling: application services do not depend on CSV, JSON, filesystem paths, or stdout.
- Test coverage: behavior is covered through unit, integration, and functional PHPUnit tests.

## Tradeoffs

- The confidence model is heuristic and deterministic, not statistically calibrated.
- Only one contact/channel is emitted per company because one good payment contact is enough for the demo output.
- Address matching is intentionally limited; the current matcher mainly normalizes company names.
- JSONL checkpoints are not automatic resume checkpoints.
- Phone validation is pragmatic and comparison-oriented rather than full numbering-plan validation.

## Improvement Points

- Add richer address matching if fixture complexity increases.
- Externalize scoring weights if calibration becomes important.
- Add structured JSON logs for operational monitoring.
- Add full checkpoint resume semantics for interrupted JSONL runs.
- Add result metadata for `review_state` if the public output shape is expanded.