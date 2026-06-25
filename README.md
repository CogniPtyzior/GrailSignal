# Grail Signal

Grail Signal is a public PHP demo for finding a likely B2B payment contact from a small company list. It reads a CSV,
consults local mocked sources, merges the available evidence, scores the result, and writes one traceable output row per
input company.

The project intentionally favors precision over recall. If the available evidence is weak, conflicting, personal,
non-business, or not traceable enough, the row is routed to human review instead of exposing a contact channel.

## Objective

The repository demonstrates a compact contact-finding batch with production-minded boundaries:

- deterministic behavior over mocked data only;
- no real API calls, scraping, queue, database, CRM, or web framework;
- field-level provenance for every returned value;
- configurable confidence threshold and suppression rules;
- explicit human-review gating for uncertain rows;
- PHPUnit coverage for domain invariants, merge behavior, scoring, adapters, and the CLI path.

## What It Does

- reads company inputs from `Data/companies.csv`;
- loads mocked business registry, business directory, and contact signal evidence from `Mocks/contact_signals.json`;
- selects at most one contact identity and one preferred channel per company;
- filters personal email domains and invalid email values;
- computes an explainable `confidence_score`;
- clears `contact_email_or_phone` when confidence is below `70` or when evidence is conflicting;
- records provenance in the `source` field;
- applies optional company/channel suppression before final output;
- writes timestamped results, logs, and optional checkpoints under `Storage/ContactFinder/`.

## Architecture

The code uses a lightweight hexagonal structure under `ContactFinder/`:

- `Domain/`: immutable value objects for inputs, evidence, merged evidence, results, and review states.
- `Application/`: orchestration, contact merging, confidence scoring, review policy, and suppression gating.
- `Ports/`: contracts for input reading, source lookup, result writing, and suppression checks.
- `Infrastructure/`: CSV reader, mock-source provider, JSON/JSONL writers, log writer, checkpoint writer, and suppression adapters.
- `Tests/`: PHPUnit unit, integration, and functional tests.

The CLI entry point is `run_contact_finder.php`. It manually composes the adapters and application services; there is no
framework container.

## Processing Flow

1. `CsvCompanyInputReader` streams `company_name` and `mailing_address` rows.
2. `MockSourceProvider` resolves the company against local fixtures using exact lookup first, then safe canonical-name matching.
3. `ContactMerger` selects deterministic identity/channel fields and attaches selected-field provenance.
4. `ConfidenceScorer` scores source diversity, agreement, selected channel confidence, target role quality, payment inboxes, and risk signals.
5. `ReviewPolicy` applies the confidence threshold and conflict rules.
6. `FindContacts` applies final suppression and returns exactly one result per input row.
7. `JsonResultWriter` or `JsonLinesResultWriter` writes the configured output format.

## Scoring And Review Rules

The default threshold is `70`.

- `confidence_score < 70` means `contact_email_or_phone` is empty and `needs_human_review` is `true`.
- conflicts force review even if supporting evidence exists;
- registered-agent-only evidence is not treated as a usable payment contact;
- phone-only contacts are capped below the threshold unless the phone is corroborated;
- strong payment inboxes such as `billing@`, `accounting@`, and `ap@` can be usable when provider confidence is high enough;
- weak single-source guesses remain review-only.

The target role priority is documented in `SPECIFICATIONS.md`: accounts payable first, then owner/founder for small
businesses, then finance director/CFO, then office manager as fallback.

## Output Shape

Each output row contains:

- `company_name`
- `contact_name`
- `contact_role`
- `contact_email_or_phone`
- `confidence_score`
- `source`
- `needs_human_review`

The `source` field includes field-level `mock://...` provenance when a value is selected, plus a final `decision=...`
marker such as `usable`, `review_required`, `conflict`, or `cannot_verify`.

## Install

```bash
composer install
```

## Run

```bash
php run_contact_finder.php
```

The runner uses `contact_finder.config.php` by default. Set `CONTACT_FINDER_CONFIG_PATH` to run with another config file.

A normal run currently produces:

```text
Inputs: 30
Outputs: 30
Usable: 7
Needs review: 23
Cannot verify: 12
Conflicts: 1
```

## Configuration

Important config keys in `contact_finder.config.php`:

- `input_csv_path`: input CSV path.
- `mock_source_path`: local mock fixture path.
- `suppression_list_path`: optional JSON suppression list.
- `output_directory`: result output directory.
- `logs_directory`: execution log directory.
- `checkpoint_directory`: checkpoint directory for JSONL mode.
- `checkpoint_every`: checkpoint cadence.
- `confidence_threshold`: default `70`.
- `output_format`: `json` or `jsonl`.

Suppression JSON shape:

```json
{
  "companies": ["Grail Signal Demo 001 SARL"],
  "channels": ["contact.demo001@gsf-001.example"]
}
```

## Tests

Run the PHPUnit suite with:

```bash
composer test
```

The suite covers domain invariants, merge behavior, scoring caps, suppression, mock-source validation, CSV parsing,
result writers, and CLI behavior.

Useful validation commands:

```bash
composer validate --no-check-publish
php -l run_contact_finder.php
```

## Compliance Boundaries

Grail Signal is deliberately constrained:

- local mocks only;
- no real API calls;
- no web scraping;
- business contact data only;
- personal email domains are filtered;
- opt-out/suppression is supported;
- source provenance is mandatory for emitted values;
- uncertain rows are routed to human review.

## Documentation

- [SPECIFICATIONS.md](SPECIFICATIONS.md): functional rules and constraints.
- [ARCHITECTURE.md](ARCHITECTURE.md): deeper module structure and design tradeoffs.
- [SETUP.md](SETUP.md): local usage notes.
- [CONTEXT.md](CONTEXT.md): project context, source strategy, scoring principles, and review philosophy.
- [THINKING.md](THINKING.md): design rationale.
