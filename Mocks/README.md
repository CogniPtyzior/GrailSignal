# Mock Providers

Grail Signal uses canned fixtures instead of real APIs or scraping. The fixtures simulate three independent and
individually fallible business data sources.

All responses live in [`contact_signals.json`](contact_signals.json), keyed by `company_name` exactly as it
appears in `Data/companies.csv`.

The fixture data is intentionally fictional. Company names, contact names, addresses, and source URLs use explicit demo
identifiers. Email addresses use reserved `.example` domains, and phone numbers use French numbering ranges reserved for
fiction.

## The Three Providers

1. **business_registry**: a business registry lookup. Sometimes returns a legal representative name. Often missing for tiny businesses.
2. **business_directory**: a web/maps business directory. May return a generic business phone and sometimes a role-less name.
3. **contact_signal**: a contact signal source. Returns a possible email/phone with its own `provider_confidence`
   from 0 to 100. Sometimes returns nothing, sometimes returns a plausible-but-weak guess.

## Response Shape

```json
{
  "Grail Signal Demo 001 SARL": {
    "business_registry":   { "name": "Contact Demo 001", "role": "Owner", "source_url": "mock://business-registry/gsf-001" },
    "business_directory":    { "name": "Contact Demo 001", "phone": "+33 1 99 00 01 01", "source_url": "mock://business-directory/gsf-001" },
    "contact_signal": { "email": "contact.demo001@gsf-001.example", "phone": null, "provider_confidence": 84, "source_url": "mock://contact-signal/gsf-001" }
  }
}
```

Rules the mocks enforce:

- A provider can return `null` fields or be entirely absent for a company.
- `contact_signal.provider_confidence` is the source's self-reported confidence. It is not the final `confidence_score`.
- Some companies have only one source, some have none, some have agreeing sources, and some have a single weak
  contact signal.
- `source_url` values are `mock://...` strings. Carry them through as provenance.

## What Good Looks Like

- Cross-reference providers; agreement raises confidence.
- Never emit a contact that cannot be attributed to at least one `source_url`.
- Rows below the confidence threshold come back with `needs_human_review = true` and an empty contact.





