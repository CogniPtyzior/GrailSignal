# Grail Signal Setup

## Requirements

- PHP 8.2 or newer
- Composer

## Install Dependencies

From the repository root:

```bash
composer install
```

## Run The Batch

From the repository root:

```bash
php run_contact_finder.php
```

The runner reads:

- `Data/companies.csv`
- `Mocks/contact_signals.json`
- `contact_finder.config.php`

Optional suppression rules can be enabled by setting `suppression_list_path` in `contact_finder.config.php`.
The JSON shape is:

```json
{
  "companies": ["Grail Signal Demo 001 SARL"],
  "channels": ["contact.demo001@gsf-001.example"]
}
```

It writes generated files under:

- `Storage/ContactFinder/Results/`
- `Storage/ContactFinder/Logs/`

Each run uses a timestamp with microseconds in the generated filenames, so repeated runs do not overwrite previous
result or log files.

Those generated directories are intentionally ignored by Git.

Set `output_format` to `jsonl` to write one result per line as rows are processed. In that mode the runner also updates a lightweight checkpoint file according to `checkpoint_every`.

## Expected Output

A successful run prints a short summary similar to:

```text
Contact Finder completed successfully.
Inputs: 30
Outputs: 30
Usable: 7
Needs review: 23
Cannot verify: 12
Conflicts: 1
Results: Storage/ContactFinder/Results/contact-finder-results-YYYYMMDD-HHMMSS-ffffff.json
Log: Storage/ContactFinder/Logs/contact-finder-run-YYYYMMDD-HHMMSS-ffffff.log
```

The JSON result contains one row per input company with:

- `company_name`
- `contact_name`
- `contact_role`
- `contact_email_or_phone`
- `confidence_score`
- `source`
- `needs_human_review`

Rows below the configured confidence threshold keep `contact_email_or_phone` empty and set `needs_human_review` to true.

Known failures print a concise error message to stderr and append `status=failed`, the error type, the error class, and
the message to the same execution log when the log file has already been started.






