# PATCH /api/v1/institution

Changes the profile of the signed-in owner's institution. Every field is optional; only the
fields present are changed.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-02, NFR-SEC-01 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

Body, JSON. Strings are trimmed; for the optional fields an empty string means "clear it"
(stored as `null`).

| Field | Type | Rules |
|---|---|---|
| name | string | 1 to 150 characters after trimming; cannot be cleared |
| type | string | `school`, `university`, `association` or `other` |
| description | string, null | at most 500 characters |
| address | string, null | at most 255 characters |
| city | string, null | at most 100 characters |
| phone | string, null | at most 30 characters, only digits, spaces and `+ ( ) . -` |
| contact_email | string, null | a valid email address, at most 255 characters |
| timezone | string | a valid IANA time zone identifier, such as `America/Port-au-Prince` |
| language | string | `fr` or `en` (the default language of the institution; a user's own language is separate) |

```json
{ "name": "Collège Étoile du Matin", "city": "Port-au-Prince", "timezone": "America/Port-au-Prince" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid body with some fields | 200 | | |
| 2 | Empty body, or no known field | 200 | | |
| 3 | An optional field sent as an empty string | 200 | | |
| 4 | `name` empty, blank or sent as `null` | 422 | `validation_failed` (`name: required`) | |
| 5 | `name` longer than 150 characters | 422 | `validation_failed` (`name: max`) | |
| 6 | `type` not one of the four | 422 | `validation_failed` (`type: in`) | |
| 7 | `description`, `address`, `city`, `phone` too long | 422 | `validation_failed` (`<field>: max`) | |
| 8 | `phone` with a letter or another symbol | 422 | `validation_failed` (`phone: format`) | |
| 9 | `contact_email` not an address | 422 | `validation_failed` (`contact_email: email`) | |
| 10 | `timezone` not a known identifier | 422 | `validation_failed` (`timezone: timezone`) | |
| 11 | `language` not `fr` or `en` | 422 | `validation_failed` (`language: in`) | |
| 12 | The user is a manager | 403 | `forbidden` | |
| 13 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 14 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 15 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 16 | Body is not valid JSON | 400 | `malformed_request` | |
| 17 | Another method than GET, HEAD, PATCH | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

The same body as [GET /institution](GET-institution.md), with the new values.

### 422 — scenarios 4 to 11

```json
{ "error": { "code": "validation_failed", "message": "Les données envoyées ne sont pas valides.", "fields": { "timezone": ["timezone"] } } }
```

### 403 — scenario 12

```json
{ "error": { "code": "forbidden", "message": "Vous n'avez pas le droit de faire cela." } }
```

### 401, 403 (suspended), 419, 400, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

- Fields that are not in the table are ignored, so a request cannot change `suspended_at`,
  the logo (see [PUT /institution/logo](PUT-institution-logo.md)) or the institution's `id`.
- Only the signed-in owner's own institution can change; a manager gets 403 (the resource is
  their own, so there is no existence to hide).
- Changing `language` changes the default language of the institution only, not the
  language already stored for each user.
- Nothing is logged but the request id and the outcome.
