# CONTEXT.md

## Project Context

This document describes the broader design frame for Grail Signal: what a reliable B2B contact-qualification system is meant to optimize for, which source signals matter, and how evidence can be merged, scored, and reviewed.

The implementation is deliberately narrower. The concrete behavior of this demo is bounded by `SPECIFICATIONS.md`, which is the authoritative reference for supported inputs, output rules, scoring constraints, and test expectations.

Grail Signal is a deterministic contact-qualification demo for B2B payment workflows. At a high level, it takes company names and mailing addresses, consolidates simulated business signals, and returns either a usable payment-relevant contact channel or an explicit review outcome.

Input:

* `company_name`
* `mailing_address`

Output:

* `contact_name`
* `contact_role`
* `contact_email_or_phone`
* `confidence_score`
* `source`
* `needs_human_review`

The design favors precision and auditability. It does not treat coverage as a reason to invent contact data. No evidence is a valid outcome.

## Conceptual Processing Model

1. **Normalize input**

   * Preserves original values for audit.
   * Normalizes company and address values for matching.
   * Trims outer whitespace and removes control or unsupported characters.
   * Reduces punctuation noise and legal suffix variation.
   * Parses address components when useful.

2. **Create matching keys**

   * `company_key`: normalized company name without legal suffix.
   * `coarse_location_key`: city, region, and country.
   * `postal_key`: postal code.
   * `street_address_key`: normalized street address as supporting evidence.

3. **Collect source signals**

   * Reads deterministic mock sources in this demo.
   * Keeps raw evidence and provider confidence visible.
   * Treats provider confidence as one signal among others, scoped to the retained email or phone channel.

4. **Build contact options**

   * Extracts people, roles, emails, phones, company-level channels, and source metadata.
   * Tracks provenance per field.
   * Allows compatible fields from different sources to form one final contact option.

5. **Merge and select fields**

   * Merges only when evidence likely points to the same person or company channel.
   * Selects the strongest supported name, role, email, phone, and company match.
   * Ranks email evidence separately from phone evidence.
   * Treats conflicting identity or channel evidence as a review risk.

6. **Score and classify**

   * Computes one global `confidence_score` for the selected contact.
   * Classifies the row as `usable`, `review_required`, `conflict`, or `cannot_verify`.
   * Marks rows for human review whenever they are not meant to be used directly.

## Source Strategy

The demo models several source families and assigns trust at field level rather than source level.

Source types:

* **Business registry:** legal identity, officers, registered address.
* **Business directory:** operational phone, address confirmation, business status.
* **Contact signal:** business emails, possible direct contacts, phone corroboration.
* **Client records:** known contacts or past contactability when available.

Common failure modes:

* stale data
* legal name versus trading name mismatch
* multi-location ambiguity
* generic inboxes
* registered agents or filing agents
* personal or home data
* weak provenance
* conflicting people or channels

Email evidence priority:

1. verified direct payment-relevant business email
2. verified payment-specific inbox: `billing@`, `accounting@`, `ap@`
3. email corroborated by multiple sources
4. generic company inbox
5. inferred or weakly sourced email

Phone evidence priority:

1. phone confirmed by multiple sources
2. operational phone from a business directory
3. phone from a contact signal source
4. unverified or weakly sourced phone

In this model, the selected `contact_email_or_phone` comes from the strongest usable channel, not from the first source returned.

## Merge Quality

Strong merge signals:

* same email
* same phone
* same full name and company
* same name with matching domain or address
* same person confirmed by independent sources

Weak merge signals:

* initials or nickname match
* same role without matching name
* same company but different channels

Conflict signals:

* different named people for the same role
* best name source and best email source point to different people
* same email attached to inconsistent names
* address or domain mismatch

## Confidence Scoring

`confidence_score` is the global usability score for the selected contact. It combines:

* identity confidence
* role and payment relevance
* selected email confidence
* selected phone confidence
* source agreement
* provenance quality
* conflict penalties

Confidence increases when independent sources agree, the role or channel is payment-relevant, the email uses a business domain, the phone is corroborated, company and location align, and provenance is clear.

Confidence decreases when sources disagree, company or address matching is weak, the only channel is generic, data appears personal, provider confidence belongs to a non-retained channel, the person is only a registered or filing agent, or provenance is missing.

A strong single-source payment contact can be usable, but equivalent multi-source corroboration remains stronger evidence.

## Provenance

`source` summarizes ranked field-level evidence and the final decision.

Format:

```text
email=<ranked email evidence>; phone=<ranked phone evidence>; identity=<name/role/company evidence>; decision=<usable/review/conflict/cannot_verify reason>
```

Examples:

```text
email=contact_signal[direct_business_email,high]+business_directory[domain_match]; phone=business_directory[operational_phone,medium]; identity=business_registry[name,role]+business_directory[address_match]; decision=usable
```

```text
email=contact_signal[inferred_email,low]; phone=business_directory[generic_phone,medium]; identity=business_registry[name]+contact_signal[conflicting_name]; decision=conflict
```

```text
email=none; phone=none; identity=no_match; decision=cannot_verify
```

## Review Philosophy

No evidence is a valid result. Low-confidence options can be retained as evidence without being exposed as the primary usable contact channel.

The design prefers review over contacting a questionable person. Generic inboxes and low-confidence channels are fallbacks, not primary targets.

## Privacy And Compliance

Grail Signal is scoped to business-relevant data. The demo data is fictitious and avoids real personal contacts.

The project supports these principles:

* use public, licensed, client-provided, or simulated business sources
* keep provenance for every returned value
* separate person-level contacts from company-level channels
* support opt-out, suppression, and deletion
* keep an audit trail
* avoid personal or home contact data
* avoid sensitive or protected traits
* avoid bypassing access controls
* avoid treating registered agents as payment decision-makers by default
* avoid returning precise contact data without evidence
