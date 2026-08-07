# AD Employee Data Structure — Full Breakdown

**Date pulled:** 2026-08-03
**Source:** Microsoft Graph API (`app/Services/MicrosoftAdService.php`), live query, not a DB record
**Sample employee:** Abdulrahim Ebrahim (`abdulrahim@cosmos-pharm.com`)
**Total users returned by this query:** 400

---

## 1. Direct answer to the question

**The IT manager is correct — department AND sub-department both exist in the AD data.** They are not two separate flat fields though; they come from one structure (the Distinguished Name), and only one level of it made it into the visible UI. That is almost certainly why you never noticed it:

| What | AD label | Value for this employee | Stored in DB? | Shown in UI? |
|---|---|---|---|---|
| Department | `department` (flat Graph field) | `Quality Assurance` | ✅ `employees.department_id` | ✅ yes, throughout the app |
| Sub-department | 1st OU in the DN | `CSV` | ✅ `employees.section` | ⚠️ only in the AD Sync **preview** table (`resources/views/livewire/admin/employees/index.blade.php:3357-3418`) — **not** on the employee profile/list pages |
| Division | 3rd OU in the DN | `Quality` | ✅ `employees.division` | ⚠️ same as above — preview table only |

So the sub-department (`section` in the code) is pulled from AD and saved to the database on every sync — it's just labeled **"Section"**, not "Sub-department," and it's only rendered once, in a modal you'd only see mid-sync. It never appears on the main employee record. That's the disconnect.

---

## 2. Raw record pulled directly from AD (Microsoft Graph)

This is the exact, unmodified JSON returned by `MicrosoftAdService::getAllUsers()` for one person — no database involved.

```json
{
    "id": "b649a6de-b975-4448-bf7b-a37f22f4289a",
    "displayName": "Abdulrahim Ebrahim",
    "givenName": "Abdulrahim Ebrahim",
    "surname": "Ebrahim",
    "jobTitle": "Quality Assurance (CSV) Specialist",
    "mail": "abdulrahim@cosmos-pharm.com",
    "mobilePhone": "0724390927",
    "businessPhones": ["0724390927"],
    "userPrincipalName": "abdulrahim@cosmos-pharm.com",
    "department": "Quality Assurance",
    "employeeId": "M1ALI748",
    "companyName": "Cosmos Limited",
    "officeLocation": null,
    "accountEnabled": true,
    "onPremisesSamAccountName": "abdulrahim",
    "onPremisesDistinguishedName": "CN=Abdulrahim Ebrahim,OU=CSV,OU=Quality Assurance,OU=Quality,OU=COSMOS,DC=cosmos,DC=local",
    "onPremisesExtensionAttributes": {
        "extensionAttribute1": "CSV - Quality Assurance - Quality - COSMOS",
        "extensionAttribute2": null,
        "extensionAttribute3": null,
        "extensionAttribute4": null,
        "extensionAttribute5": null,
        "extensionAttribute6": null,
        "extensionAttribute7": null,
        "extensionAttribute8": null,
        "extensionAttribute9": null,
        "extensionAttribute10": null,
        "extensionAttribute11": null,
        "extensionAttribute12": null,
        "extensionAttribute13": null,
        "extensionAttribute14": null,
        "extensionAttribute15": null
    }
}
```

---

## 3. Field-by-field reference

| Field | Example value | What it is |
|---|---|---|
| `id` | `b649a6de-...` | Azure AD object GUID — stored locally as `employees.ad_object_id` |
| `displayName` | `Abdulrahim Ebrahim` | Full name |
| `givenName` / `surname` | `Abdulrahim Ebrahim` / `Ebrahim` | First/last name split |
| `jobTitle` | `Quality Assurance (CSV) Specialist` | Free-text job title, set manually in AD — often echoes the department/sub-department but isn't structured |
| `mail` | `abdulrahim@cosmos-pharm.com` | Primary email |
| `mobilePhone` / `businessPhones` | `0724390927` | Phone numbers |
| `userPrincipalName` | `abdulrahim@cosmos-pharm.com` | AD login (UPN) |
| `department` | `Quality Assurance` | **Flat department field**, set directly on the AD user object (not derived from the DN) |
| `employeeId` | `M1ALI748` | Company staff/payroll number |
| `companyName` | `Cosmos Limited` | Legal entity |
| `officeLocation` | `null` | Not populated for this user |
| `accountEnabled` | `true` | Whether the AD account is active — used to detect employees who were disabled/removed |
| `onPremisesSamAccountName` | `abdulrahim` | Legacy on-prem AD username |
| `onPremisesDistinguishedName` | `CN=...,OU=CSV,OU=Quality Assurance,OU=Quality,OU=COSMOS,DC=cosmos,DC=local` | **The full org hierarchy, encoded as nested Organizational Units** — this is where sub-department/division actually live |
| `onPremisesExtensionAttributes` | `extensionAttribute1: "CSV - Quality Assurance - Quality - COSMOS"` | Same hierarchy, pre-joined into a single readable string by whoever set up on-prem AD — a convenience field, currently unused by the app |

---

## 4. Decoding the Distinguished Name (this is the actual org hierarchy)

```
CN=Abdulrahim Ebrahim,OU=CSV,OU=Quality Assurance,OU=Quality,OU=COSMOS,DC=cosmos,DC=local
```

OUs read **innermost (closest to the person) → outermost**, so the hierarchy top-down is:

```
COSMOS                 (company / top-level OU)
 └─ Quality            (division)
     └─ Quality Assurance   (department — same value as the flat `department` field)
         └─ CSV             (sub-department / section — the level the manager says is missing)
```

`extensionAttribute1` confirms this ordering explicitly: `"CSV - Quality Assurance - Quality - COSMOS"` (sub-department → department → division → company).

---

## 5. How the codebase currently handles this

**Parsing** — `app/Services/MicrosoftAdService.php:92-101`:

```php
public function parseDnOUs(string $dn): array
{
    preg_match_all('/OU=([^,]+)/', $dn, $matches);
    $ous = $matches[1] ?? [];

    return [
        'section'  => $ous[0] ?? null, // e.g. CSV
        'division' => $ous[2] ?? null, // e.g. Quality
    ];
}
```

- `$ous[0]` ("CSV") → returned as **`section`** — this is the sub-department the manager is referring to. It **is** captured.
- `$ous[1]` ("Quality Assurance") → intentionally skipped, because it's redundant with the flat `department` field pulled separately.
- `$ous[2]` ("Quality") → returned as **`division`**.
- `$ous[3]` ("COSMOS") → not used (company-level, same for everyone in this org).

**Saving** — `resources/views/livewire/admin/employees/index.blade.php`, both `commitAdSync()` branches (new employee: line 509-511, existing employee update: line 452-454):

```php
'section' => $row['section'],
'division' => $row['division'],
'department_id' => $this->resolveDepartment($org, $row['department']) ?? ...,
```

So on every AD sync, all three values are written to the database:

| AD concept | DB column (`employees` table) |
|---|---|
| Department (`Quality Assurance`) | `department_id` (resolved to a `departments` row) |
| Sub-department (`CSV`) | `section` |
| Division (`Quality`) | `division` |

**Display** — the only place `section`/`division` are rendered anywhere in the UI is the AD Sync **preview modal**, `resources/views/livewire/admin/employees/index.blade.php:3357` (header "Section") and `:3415-3418` (value). They do **not** appear on:
- the employee list/index table
- the individual employee profile page (`employees/view.blade.php`)
- any report or export reviewed so far

---

## 6. Why you never saw it

The data is pulled from AD and saved into `employees.section` and `employees.division` correctly on every sync — the manager isn't wrong that it exists. It's just:

1. Named `section`, not `sub_department`, anywhere in the code/schema.
2. Only ever displayed once, in the AD sync preview table — a modal you'd only see while actively running a sync, not while browsing employee records.

## 7. Suggested next step (not yet done — for your decision)

If you want sub-department to actually be usable (filterable, visible on the employee profile, in reports, etc.), the data is already there — it would just need surfacing:
- Add `section`/`division` columns to the employee list & profile views.
- Rename `section` → `sub_department` in the UI copy (and optionally the DB column) for clarity, since that's the term the IT manager and presumably the business use.

Nothing has been changed in the codebase — this document is purely a data audit.
