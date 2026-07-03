# Leave Approval & Per-Department Overrides — Deployment Guide

Covers everything added on the `hf-leave-requests` branch: leave types, leave
balances, the multi-level approval chain (configurable per department, from
the same System Settings screen), approval-progress on the API, and the
balance-exhausted guard on applying.

For API endpoint documentation (request/response shapes, curl examples), see
[`LEAVE_API.md`](LEAVE_API.md).

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
  owner-scoped — same authorization as `GET /api/leaves/{id}`). Full
  endpoint list in [`LEAVE_API.md`](LEAVE_API.md).

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
- [ ] Edit a leave balance from System Settings → Leave Balances; confirm the
      change reflects in the report, the employee-facing API, and the
      apply-time balance check
