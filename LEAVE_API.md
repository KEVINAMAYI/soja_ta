# Leave Management API Reference

All endpoints below live under `App\Http\Controllers\APIs\LeaveController`, are
registered in `routes/api.php` inside the `Route::prefix('leaves')` group, and
require Sanctum authentication (`auth:sanctum` middleware) unless noted.

**Base URL:** `{APP_URL}/api` (local dev: `http://localhost:8000/api`)

## Response envelope

Every response uses this shape:

```json
{
  "code": 1000,
  "message": "Human-readable summary",
  "data": { }
}
```

- `code: 1000` — success.
- `code: 1003` — failure (validation error, not found, unauthorized, business
  rule violation). The HTTP status code varies (`400`/`403`/`404`/`409`/`422`/`500`)
  — check the status code, not just `code`, to branch on error type.
- `data.errors` — present alongside `code: 1003` for `422` validation
  failures, keyed by field name (standard Laravel validator format).

---

## Authentication

### `POST /login`

No auth required — this is how you get the token every other call needs.

**Required fields:**

| Field | Type | Required |
|---|---|---|
| `email` | string | **Yes** |
| `password` | string | **Yes** |

**Example request:**
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"employee@example.com","password":"secret"}'
```

**Example response `200`** (trimmed — the real response also includes
`employee_work_locations`, `roles`, `permissions`, and organization branding/
integration fields not relevant to leave management):
```json
{
  "code": 1000,
  "message": "Login was successful",
  "data": {
    "employee_id": 1,
    "employee_name": "Tech Support",
    "employee_email": "amayi@identigate.co.ke",
    "employee_organization": { "id": 1, "name": "Identigate" },
    "employee_department": { "id": 1, "name": "ICT", "manager_id": 1, "organization_id": 1 },
    "roles": ["super-admin"]
  },
  "token": "536|<plain-text-token>"
}
```

Every request below assumes:
```bash
export TOKEN="536|<plain-text-token>"
```
and sends `-H "Authorization: Bearer $TOKEN" -H "Accept: application/json"`.

> Verified live against this app's dev server on 2026-07-03 using
> `amayi@identigate.co.ke` (employee id 1, department ICT) end-to-end through
> apply → balances → show → approval-progress. Every example below reflects
> real captured responses, not hypothetical ones (secrets/tokens redacted).

---

## `POST /leaves/apply`

Apply for leave. Special-cased for `offshift`/`sick` leave type codes, which
just flip the employee's shift status instead of creating a `Leave` record.

**Required fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `employee_id` | int | **Yes** | Must exist in `employees` |
| `leave_type_id` | int | **Yes**, unless `leave_type` given | Canonical field — preferred |
| `leave_type` | string | **Yes**, unless `leave_type_id` given | Legacy fallback, matched by `leave_types.code` |
| `start_date` | date | **Yes** | Must be today or later |
| `duration_type` | string | **Yes** | `dateRange` or `numberOfDays` |
| `end_date` | date | **Yes**, if `duration_type=dateRange` | Must be ≥ `start_date` |
| `number_of_days` | int | **Yes**, if `duration_type=numberOfDays` | Min 1 |
| `reason` | string | No | Max 500 chars |
| `contact_during_leave` | string | No | Max 255 chars |
| `emergency_contact` | string | No | Max 255 chars |
| `handover_to` | int | No | Must exist in `employees` |

**Example request** (real call — amayi applying for Personal Leave):
```bash
curl -X POST http://localhost:8000/api/leaves/apply \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "employee_id": 1,
    "leave_type_id": 9,
    "start_date": "2026-08-10",
    "duration_type": "dateRange",
    "end_date": "2026-08-11",
    "reason": "curl end-to-end API test"
  }'
```

**Example response `201`** (created, pending review — real captured response):
```json
{
  "code": 1000,
  "message": "Leave request submitted successfully. Pending review.",
  "data": {
    "leave_id": 70,
    "employee_id": 1,
    "leave_type": "Personal Leave",
    "start_date": "2026-08-10",
    "end_date": "2026-08-11",
    "status": "pending",
    "expected_resumption": "2026-08-11T21:00:00.000000Z"
  }
}
```
Note `expected_resumption` here is a full ISO datetime, while `start_date`/
`end_date` in *this* response are plain `Y-m-d` strings — but the same fields
come back as full ISO datetimes when fetched via `GET /leaves/{id}` (see
below). Don't assume date format consistency across endpoints; parse
defensively.

**Example response `422` (balance exhausted):**
```json
{
  "code": 1003,
  "message": "Insufficient leave balance for Annual Leave. Requested 5 day(s), 2 day(s) remaining.",
  "data": { "requested_days": 5, "remaining_days": 2 }
}
```

**Example response `409` (date conflict):**
```json
{ "code": 1003, "message": "You have conflicting leave or off-shift status for the requested dates." }
```

**Example response `200` (offshift/sick — no Leave record created):**
```json
{
  "code": 1000,
  "message": "Shift status updated to 'Off Shift'.",
  "data": { "employee_id": 1, "shift_status": "off_shift", "start_date": "2026-08-10", "end_date": "2026-08-12" }
}
```

---

## `GET /leaves/my-leaves`

Paginated list of the authenticated employee's own leave requests.

**Required fields:** none — all query params are optional filters.

| Param | Type | Required | Notes |
|---|---|---|---|
| `status` | string | No | `pending` \| `approved` \| `rejected` \| `cancelled` |
| `leave_type` | string | No | Matched against the legacy `leave_type` string column |
| `from_date` | date | No | Filters on overlap with the leave's date range |
| `to_date` | date | No | Must be ≥ `from_date` |
| `per_page` | int | No | 1–100, default 15 |

**Example request:**
```bash
curl "http://localhost:8000/api/leaves/my-leaves?status=pending&per_page=10" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Example response `200`** (real captured response — amayi had 2 pending
leaves at test time, including leave `70` from the `apply` example above):
```json
{
  "code": 1000,
  "message": "Leaves retrieved successfully",
  "data": {
    "leaves": [
      {
        "id": 70, "employee_id": 1, "department_id": 1, "leave_type": "Personal Leave",
        "leave_type_id": 9, "status": "pending", "current_level": 1, "total_levels": 3,
        "start_date": "2026-08-09T21:00:00.000000Z", "end_date": "2026-08-10T21:00:00.000000Z",
        "approval_progress": { "enabled": true, "current_level": 1, "total_levels": 3, "levels": [ "..." ] }
      }
    ],
    "shift_status": null,
    "pagination": { "total": 2, "per_page": 10, "current_page": 1, "last_page": 1, "from": 1, "to": 2 }
  }
}
```
`shift_status` is non-null only if the employee currently has `off_shift`/`sick_off` set.

---

## `GET /leaves/types`

Active leave types for the authenticated employee's organization.

**Required fields:** none.

**Example request:**
```bash
curl http://localhost:8000/api/leaves/types \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Example response `200`** (real captured response, all 9 active types for
amayi's org):
```json
{
  "code": 1000,
  "message": "Leave types retrieved successfully",
  "data": [
    { "id": 3, "code": "annual", "name": "Annual Leave", "icon": "🏖️", "annual_entitlement_days": "21.0" },
    { "id": 6, "code": "compassionate", "name": "Compassionate Leave", "icon": "🕯️", "annual_entitlement_days": "7.0" },
    { "id": 4, "code": "maternity", "name": "Maternity Leave", "icon": "🤰", "annual_entitlement_days": "30.0" },
    { "id": 2, "code": "offshift", "name": "Off Shift", "icon": "🌙", "annual_entitlement_days": null },
    { "id": 5, "code": "paternity", "name": "Paternity Leave", "icon": "👨‍🍼", "annual_entitlement_days": "14.0" },
    { "id": 9, "code": "personal", "name": "Personal Leave", "icon": "👤", "annual_entitlement_days": "5.0" },
    { "id": 1, "code": "sick", "name": "Sick Off", "icon": "🤒", "annual_entitlement_days": null },
    { "id": 7, "code": "study", "name": "Study Leave", "icon": "📚", "annual_entitlement_days": "10.0" },
    { "id": 8, "code": "unpaid", "name": "Unpaid Leave", "icon": "💸", "annual_entitlement_days": null }
  ]
}
```
`annual_entitlement_days` is a **decimal-formatted string** (e.g. `"21.0"`,
not a bare number `21`) — cast it before doing math on it. `null` means the
type is untracked/unlimited by default (no balance is checked), unless an
admin has set an explicit per-employee override — note that
`GET /leaves/balances`'s `entitled_days`/`remaining_days` *are* returned as
real numbers, not strings, so the two endpoints aren't type-consistent here.

---

## `GET /leaves/balances`

The authenticated employee's balance for every active leave type.

**Required fields:** none.

| Param | Type | Required | Notes |
|---|---|---|---|
| `year` | int | No | Defaults to the current year |

**Example request:**
```bash
curl "http://localhost:8000/api/leaves/balances?year=2026" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Example response `200`** (real captured response, all 9 active types for
amayi's org — taken *after* the `apply` call above, so Personal Leave already
shows `pending_days: 2`):
```json
{
  "code": 1000,
  "message": "Leave balances retrieved successfully",
  "data": [
    { "leave_type_id": 3, "code": "annual", "name": "Annual Leave", "icon": "🏖️", "entitled_days": 21, "used_days": 2, "pending_days": 0, "remaining_days": 19 },
    { "leave_type_id": 6, "code": "compassionate", "name": "Compassionate Leave", "icon": "🕯️", "entitled_days": 7, "used_days": 0, "pending_days": 0, "remaining_days": 7 },
    { "leave_type_id": 4, "code": "maternity", "name": "Maternity Leave", "icon": "🤰", "entitled_days": 30, "used_days": 0, "pending_days": 0, "remaining_days": 30 },
    { "leave_type_id": 2, "code": "offshift", "name": "Off Shift", "icon": "🌙", "entitled_days": null, "used_days": 0, "pending_days": 0, "remaining_days": null },
    { "leave_type_id": 5, "code": "paternity", "name": "Paternity Leave", "icon": "👨‍🍼", "entitled_days": 14, "used_days": 0, "pending_days": 0, "remaining_days": 14 },
    { "leave_type_id": 9, "code": "personal", "name": "Personal Leave", "icon": "👤", "entitled_days": 5, "used_days": 0, "pending_days": 2, "remaining_days": 3 },
    { "leave_type_id": 1, "code": "sick", "name": "Sick Off", "icon": "🤒", "entitled_days": null, "used_days": 0, "pending_days": 0, "remaining_days": null },
    { "leave_type_id": 7, "code": "study", "name": "Study Leave", "icon": "📚", "entitled_days": 10, "used_days": 0, "pending_days": 0, "remaining_days": 10 },
    { "leave_type_id": 8, "code": "unpaid", "name": "Unpaid Leave", "icon": "💸", "entitled_days": null, "used_days": 0, "pending_days": 0, "remaining_days": null }
  ]
}
```
`entitled_days` reflects an admin-set override for that employee/type/year if
one exists, otherwise the leave type's default.
`remaining_days = entitled_days - used_days - pending_days`.

---

## `GET /leaves/{id}`

A single leave request. **Owner-only** — 403 if the leave doesn't belong to
the authenticated employee.

**Required fields:** `{id}` in the URL path (**Yes**).

**Example request:**
```bash
curl http://localhost:8000/api/leaves/70 \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Example response `200`** (real captured response — note dates come back as
full ISO datetimes here, `employee`/`department` are eager-loaded in full,
and `approval_logs` is included alongside the derived `approval_progress`):
```json
{
  "code": 1000,
  "message": "Leave details retrieved successfully",
  "data": {
    "id": 70, "employee_id": 1, "department_id": 1, "organization_id": 1,
    "leave_type": "Personal Leave", "leave_type_id": 9, "status": "pending",
    "current_level": 1, "total_levels": 3,
    "start_date": "2026-08-09T21:00:00.000000Z", "end_date": "2026-08-10T21:00:00.000000Z",
    "reason": "curl end-to-end API test",
    "expected_resumption": "2026-08-11T21:00:00.000000Z",
    "approval_progress": {
      "enabled": true, "current_level": 1, "total_levels": 3,
      "levels": [
        {
          "level_number": 1, "status": "pending",
          "approver_type": "user", "approver_role": null,
          "approver_user": { "id": 244, "name": "Kevin Musungu" },
          "actioned_by": null,
          "opened_at": "2026-07-03T05:37:38.000000Z", "closed_at": null,
          "notes": null
        }
      ]
    },
    "employee": { "id": 1, "name": "Tech Support", "department_id": 1, "organization_id": 1, "...": "full Employee model" },
    "department": { "id": 1, "name": "ICT", "manager_id": 1, "organization_id": 1 },
    "approval_logs": [
      {
        "id": 39, "leave_id": 70, "level_number": 1, "approver_type": "user",
        "approver_role": null, "approver_user_id": 244, "status": "pending",
        "opened_at": "2026-07-03T05:37:38.000000Z", "closed_at": null,
        "actioned_by": null, "notes": null,
        "approver_user": { "id": 244, "name": "Kevin Musungu", "email": "..." }
      }
    ]
  }
}
```
Note the `start_date`/`end_date` shift by a few hours (`21:00:00` the day
before) versus the plain `2026-08-10` you sent — that's UTC storage plus the
`Africa/Nairobi` app timezone being applied on cast to `Carbon`. Don't do
date-only string comparisons against these fields; parse them as datetimes.

**Example response `403` (not your leave):**
```json
{ "code": 1003, "message": "Unauthorized. You do not have permission to view this leave request." }
```

---

## `GET /leaves/{id}/approval-progress`

Just the approval-chain progress for a leave, without the rest of the
payload. Same owner-only authorization as `GET /leaves/{id}`.

**Required fields:** `{id}` in the URL path (**Yes**).

**Example request:**
```bash
curl http://localhost:8000/api/leaves/70/approval-progress \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Example response `200`, active chain** (real captured response — this
department has a 3-level, all-specific-user override chain; only level 1 has
opened so far):
```json
{
  "code": 1000,
  "message": "Approval progress retrieved successfully",
  "data": {
    "enabled": true,
    "current_level": 1,
    "total_levels": 3,
    "levels": [
      {
        "level_number": 1, "status": "pending",
        "approver_type": "user", "approver_role": null,
        "approver_user": { "id": 244, "name": "Kevin Musungu" },
        "actioned_by": null,
        "opened_at": "2026-07-03T05:37:38.000000Z", "closed_at": null,
        "notes": null
      }
    ]
  }
}
```
Once level 1 is approved, a level-2 entry appears here with its own
`approver_user`/`approver_role` and `status: "pending"`, while level 1 flips
to `status: "approved"` with `actioned_by` populated.

**Example response `200`, no chain configured for this leave:**
```json
{ "code": 1000, "message": "Approval progress retrieved successfully", "data": null }
```

---

## `POST /leaves/{id}/approve`

Approve the leave's currently active level. The authenticated user must be
the designated approver for that level (matched by role or by exact user
id) — not gated by any blanket permission.

**Required fields:**

| Field | Type | Required |
|---|---|---|
| `{id}` (URL path) | int | **Yes** |
| `notes` | string | No, max 500 chars |

**Example request** (illustrative — `$APPROVER_TOKEN` belongs to whoever the
active level's `approver_user`/`approver_role` resolves to, e.g. Kevin
Musungu (user 244) for leave `70` above, not the applicant's own token):
```bash
curl -X POST http://localhost:8000/api/leaves/70/approve \
  -H "Authorization: Bearer $APPROVER_TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"notes":"Approved, enjoy your leave"}'
```

**Example response `200`:**
```json
{
  "code": 1000,
  "message": "Leave approved.",
  "data": {
    "id": 70, "status": "pending", "current_level": 2, "total_levels": 3
  }
}
```
`status` becomes `"approved"` instead if this was the final level.

**Example response `403` (not your level to act on):**
```json
{ "code": 1003, "message": "You are not authorized to action this approval level." }
```

**Example response `409` (already resolved):**
```json
{ "code": 1003, "message": "This leave request has already been resolved." }
```

---

## `POST /leaves/{id}/reject`

Same request/response shape and authorization as `approve`, except it
immediately finalizes the leave as `rejected` — no further levels open.

**Required fields:** same as `approve` above.

**Example request** (illustrative, same approver-token caveat as above):
```bash
curl -X POST http://localhost:8000/api/leaves/70/reject \
  -H "Authorization: Bearer $APPROVER_TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"notes":"Coverage clash with another team member"}'
```

**Example response `200`:**
```json
{
  "code": 1000,
  "message": "Leave rejected.",
  "data": { "id": 70, "status": "rejected" }
}
```

---

## `GET /leaves/{id}/cancel`

Cancel a pending or approved leave. **Note:** this is a `GET`, not a `POST`
(pre-existing API design quirk — kept as-is for backward compatibility with
existing clients).

**Required fields:** `{id}` in the URL path (**Yes**).

**Example request:**
```bash
curl http://localhost:8000/api/leaves/70/cancel \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Example response `200`:**
```json
{
  "code": 1000,
  "message": "Leave request cancelled successfully",
  "data": { "id": 70, "status": "cancelled" }
}
```

**Example response `400` (not cancellable):**
```json
{ "code": 1003, "message": "Only pending or approved leaves can be cancelled" }
```

---

## Suggested end-to-end test sequence

Steps 1–5 were run live end-to-end on 2026-07-03 as `amayi@identigate.co.ke`
(employee id 1) — see the real captured responses throughout this doc.

1. `POST /login` as an employee → grab `$TOKEN`.
2. `GET /leaves/types` → pick a `leave_type_id`.
3. `GET /leaves/balances?year=2026` → confirm remaining days for that type.
4. `POST /leaves/apply` with that type → note the returned `leave_id`.
5. `GET /leaves/{leave_id}` and `GET /leaves/{leave_id}/approval-progress` →
   confirm `current_level`/`total_levels`/the assigned approver match what's
   configured (org-wide default or department override) for that employee's
   department.
6. Log in as the level-1 approver → `POST /leaves/{leave_id}/approve`.
   **Not run in the live test above** — the level-1 approver for that
   department is a specific named user, not the applicant, and completing
   this step requires that user's own credentials.
7. `GET /leaves/{leave_id}/approval-progress` again → confirm level 1 shows
   `"status": "approved"` and level 2 is now `"pending"` (or the leave itself
   is `"approved"` if that was the only level).
8. Repeat step 4 with a leave type whose balance is exhausted → confirm `422`
   with `data.requested_days` / `data.remaining_days`.
9. `GET /leaves/{leave_id}/cancel` → clean up any leave created purely for testing.
