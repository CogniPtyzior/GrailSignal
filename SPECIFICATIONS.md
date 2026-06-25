# Product Scope

Grail Signal demonstrates a conservative B2B contact qualification workflow for payment operations. It processes synthetic company records, combines mocked business signals, scores confidence, and routes uncertain cases to human review.

This file is the product contract for the public demo. `CONTEXT.md` describes the broader design frame; this document defines the bounded behavior expected from the repository.

## Use Case

The demo identifies the best available payment-relevant contact channel for each input company. It intentionally selects a single candidate contact per company so the decision remains auditable.

The workflow favors precision over coverage. A traceable contact with strong supporting evidence is more valuable than several weak guesses. When evidence is absent, weak, conflicting, personal, or insufficiently traceable, the row remains review-only.

## Input And Output Contract

The batch reads synthetic French B2B company records from `Data/companies.csv`.

Required input fields:

* `company_name`
* `mailing_address`

Each input row produces exactly one output row with:

* `company_name`
* `contact_name`
* `contact_role`
* `contact_email_or_phone`
* `confidence_score`
* `source`
* `needs_human_review`

A usable row exposes one selected contact channel in `contact_email_or_phone`. A review-only row keeps `contact_email_or_phone` empty and sets `needs_human_review` to `true`.

## Evidence Model

The repository uses only local mock evidence under `Mocks/`. It does not call real APIs, scrape real sites, or enrich data from external services.

The mock providers represent source families that a production system might evaluate:

* `business_registry`: legal identity, officers, and registered address signals.
* `business_directory`: operational address and phone signals.
* `contact_signal`: business email, role, and channel confidence signals.

Each provider is treated as independently fallible. Provider confidence contributes to scoring only as supporting evidence for the selected contact channel; it is not a global guarantee that every field from that provider is correct.

## Contact Eligibility Rules

The target contact is the person or channel closest to payment operations.

Role priority:

1. accounts payable
2. owner or founder for smaller businesses
3. finance director or CFO
4. office manager as a fallback

The selected contact can be a person-level business contact or a payment-specific company inbox. Strong payment inboxes such as `billing@`, `accounting@`, and `ap@` can be usable when the supporting evidence is strong enough.

Personal or home contact data is not eligible. Invalid emails, personal email domains, registered-agent-only evidence, and unsupported provider records cannot produce a usable contact channel.

## Scoring Principles

The default confidence threshold is `70`.

Rows with `confidence_score < 70` are review-only:

* `contact_email_or_phone` is empty.
* `needs_human_review` is `true`.

Scoring must remain explainable from the retained evidence. Confidence increases when independent sources agree, the selected role or channel is payment-relevant, the selected email or phone is well supported, company matching is strong, and provenance is clear.

Confidence decreases when evidence is single-source and weak, sources conflict, company or address matching is uncertain, the selected channel is generic, the evidence points to personal data, provider confidence belongs to a non-selected channel, or provenance is incomplete.

Multi-source corroboration is valuable, but a strong single-source payment contact can still be usable when the selected channel is specific, business-relevant, and high-confidence.

## Human Review Policy

Rows are routed to human review when the system cannot safely expose a contact channel.

Review outcomes include:

* `review_required`: evidence exists but is below the usable threshold or needs manual validation.
* `conflict`: sources disagree about the relevant person or channel.
* `cannot_verify`: no usable evidence exists for the input company.

Conflicts force review even when some supporting evidence is present. No evidence is an acceptable final result; the system must not fill a channel only to improve coverage.

## Privacy Boundaries

The demo is limited to business-relevant data and synthetic fixtures. It avoids real personal contacts and protected characteristics.

The workflow keeps provenance for emitted values and supports opt-out or suppression rules. Suppressed companies or channels are not exposed as usable contacts.

## Runtime Scope

The project is limited to a local batch pipeline over the provided CSV and mocked sources. It writes deterministic result files, logs, and optional checkpoints under `Storage/ContactFinder/`.

The public demo does not include CRM workflows, outreach automation, external enrichment integrations, queues, databases, or web application surfaces.
