# Leave Approval & Per-Department Overrides — Deployment & API Testing Guide

Covers everything added on the `hf-leave-requests` branch: leave types, leave
balances, the multi-level approval chain (configurable per department, from
the same System Settings screen), approval-progress on the API, and the
balance-exhausted guard on applying.

## 1. Migrations (run in this order)

```
php artisan migrate
```

Runs, in order:
- `2026_07_01_090000_create_leave_types_table`
- `2026_07_01_090100_add_leave_type_id_and_levels_to_leaves_table`
- `2026_07_01_090200_create_leave_balances_table`
- `2026_07_01_090300_create_leave_approval_logs_table`
- `2026_07_02_100000_create_department_leave_approval_settings_table` (new — per-department overrides)

## 2. Seeders (manual — not wired into `DatabaseSeeder`)

```
php artisan db:seed --class=LeaveTypesSeeder
```

Run once per environment (idempotent — `firstOrCreate` per organization/code,
safe to re-run). No seeder is needed for department overrides — the table
starts empty by design, so every department inherits the organization-wide
default until an admin explicitly configures one.

## 3. New/changed screens & routes

- **Admin (web):** System Settings → **Leave Approval** tab now has a
  **Scope** selector (Organization-wide / Specific Department) at the top.
  Only one scope's config is ever shown or saved at a time — there is no
  separate admin page for this.
- **API:** `GET /api/leaves/{id}/approval-progress` (auth:sanctum,
  owner-scoped — same authorization as `GET /api/leaves/{id}`).

## 4. Backward compatibility

- A department with **no** row in `department_leave_approval_settings`
  continues to use the organization's existing settings exactly as before.
- An organization that has never configured leave approval at all keeps
  today's no-op behavior: the leave stays `pending` and must be resolved
  manually — this deploy does not turn approval chains on for anyone.
- Existing in-flight `Leave` rows are unaffected — their `current_level`/
  `total_levels` were fixed at creation time.

## 5. Manual verification checklist

- [ ] Migration ran cleanly; `department_leave_approval_settings` table exists
- [ ] System Settings → Leave Approval, scope "Organization-wide", still
      works exactly as before
- [ ] Switch scope to "Specific Department", pick a department with no
      override → banner shows "using the organization-wide default"
- [ ] Save a custom chain for that department → banner switches to "custom
      approval chain" with a reset button; submit a leave for an employee in
      that department and confirm the opened approval log uses the
      department's approver, not the org default
- [ ] Click "Reset to organization default" → department falls back to org
      config on the next leave
- [ ] `GET /api/leaves/{id}`, `/my-leaves`, and `/{id}/approval-progress` all
      return an `approval_progress` block for a leave with an active chain,
      `null` for one with none
- [ ] Submit a leave for a type with an exhausted balance → 422 with a clear
      requested/remaining days message
- [ ] Approval-required emails render correctly for both role-based and
      specific-user levels (no stray punctuation)

---

## 6. API Testing Guide

Base URL below assumes local dev (`http://localhost:8000`). All endpoints
except login require the `Authorization: Bearer <token>` header.

### 6.1 Get a token

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"employee@example.com","password":"secret"}'
```

Response includes `"token": "1|abcdef..."`. Export it for the rest of this
guide:

```bash
export TOKEN="1|abcdef..."
```

### 6.2 List leave types

```bash
curl http://localhost:8000/api/leaves/types \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### 6.3 Check leave balances (per type: entitled/used/pending/remaining)

```bash
curl "http://localhost:8000/api/leaves/balances?year=2026" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### 6.4 Apply for leave

```bash
curl -X POST http://localhost:8000/api/leaves/apply \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "employee_id": 1,
    "leave_type_id": 3,
    "start_date": "2026-08-10",
    "duration_type": "dateRange",
    "end_date": "2026-08-12",
    "reason": "Family trip"
  }'
```

Returns `422` with `data.requested_days` / `data.remaining_days` if the
leave type's balance is exhausted; otherwise `201` with the new leave.

### 6.5 List my leaves

```bash
curl "http://localhost:8000/api/leaves/my-leaves?status=pending" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### 6.6 View a single leave (includes `approval_progress`)

```bash
curl http://localhost:8000/api/leaves/{id} \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### 6.7 Approval progress only

```bash
curl http://localhost:8000/api/leaves/{id}/approval-progress \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

`data` is `null` if no approval chain was configured for that leave, else:
```json
{
  "enabled": true,
  "current_level": 2,
  "total_levels": 2,
  "levels": [
    {"level_number": 1, "status": "approved", "approver_type": "role", "approver_role": "supervisor", "approver_user": null, "actioned_by": {"id": 5, "name": "Jane"}, "opened_at": "...", "closed_at": "...", "notes": null},
    {"level_number": 2, "status": "pending", "approver_type": "user", "approver_role": null, "approver_user": {"id": 9, "name": "John"}, "actioned_by": null, "opened_at": "...", "closed_at": null, "notes": null}
  ]
}
```

### 6.8 Approve / reject (log in as the level's designated approver)

```bash
curl -X POST http://localhost:8000/api/leaves/{id}/approve \
  -H "Authorization: Bearer $APPROVER_TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"notes":"Approved, enjoy your leave"}'

curl -X POST http://localhost:8000/api/leaves/{id}/reject \
  -H "Authorization: Bearer $APPROVER_TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"notes":"Coverage clash with another team member"}'
```

Returns `403` if the authenticated user isn't the designated approver for the
leave's current level.

### 6.9 Cancel a leave

```bash
curl http://localhost:8000/api/leaves/{id}/cancel \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### Suggested test sequence for the department-override feature

1. In System Settings → Leave Approval, set an org-wide chain (e.g. level 1 =
   role `supervisor`).
2. Switch scope to a specific department, save a different chain (e.g. level
   1 = a named user).
3. Apply for leave (6.4) as an employee in that department → check
   `approval-progress` (6.7) shows the department's approver, not the role.
4. Apply for leave as an employee in a *different* department (no override)
   → confirm it uses the org-wide `supervisor` role chain instead.
