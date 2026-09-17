<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Employee;
use App\Services\MicrosoftAdService;

/**
 * READ-ONLY diagnostic. Changes nothing.
 *
 * Replays the exact conditions used by deactivateRemovedAdUsers() and commitAdSync()
 * (resources/views/livewire/admin/employees/index.blade.php) for a single employee, and
 * reports which check decides its fate — so you can see WHY an AD-deleted user is not
 * getting soft-deleted locally, on the environment that actually holds the data.
 *
 * Usage:
 *   php artisan ad:diagnose {identifier}
 *   identifier = AD object GUID  |  email  |  local employee id
 *
 * Example:
 *   php artisan ad:diagnose 0cd4fd28-4de9-4247-804b-ef477236d4c9
 */
class DiagnoseAdDeactivation extends Command
{
    protected $signature = 'ad:diagnose {identifier : AD object GUID, email, or local employee id}';

    protected $description = 'Read-only: explain whether/why an employee would be deactivated by AD sync.';

    /** Same exclusion list hard-coded in deactivateRemovedAdUsers(). */
    private array $excludedNames = ['Intern', 'windows', 'N. Tesla Meeting Room', 'Techsupport Identigate', 'Test Role', 'Test User'];

    public function handle(): int
    {
        $identifier = trim($this->argument('identifier'));

        // ── 1. Locate the local employee (withTrashed so we can see if it was already deleted) ──
        $employee = Employee::withTrashed()
            ->where('ad_object_id', $identifier)
            ->orWhere('email', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->first();

        if (!$employee) {
            $this->error("No local employee matches '{$identifier}' (by ad_object_id, email, or id).");
            $this->line('If the local row is missing entirely, the sync has nothing to deactivate — that is itself the answer.');
            return self::FAILURE;
        }

        $this->info('── LOCAL EMPLOYEE ──');
        $this->table(['Field', 'Value'], [
            ['id', $employee->id],
            ['name', $employee->name],
            ['email', $employee->email],
            ['employee_type', var_export($employee->employee_type, true)],
            ['organization_id', $employee->organization_id],
            ['ad_object_id', var_export($employee->ad_object_id, true)],
            ['ad_upn', var_export($employee->ad_upn, true)],
            ['zkbio_pin', var_export($employee->zkbio_pin, true)],
            ['deleted_at', var_export($employee->deleted_at?->toDateTimeString(), true)],
        ]);

        if ($employee->deleted_at) {
            $this->warn("This employee is ALREADY soft-deleted (deleted_at set). The sync already handled it — nothing more to do.");
            return self::SUCCESS;
        }

        // ── 2. Pull the live AD set exactly as the sync does ──
        $this->info('── PULLING LIVE AD SET (getAllUsers -> filterValidUsers) ──');
        try {
            $ad = app(MicrosoftAdService::class);
            $token = $ad->getAccessToken();
            $rawAll = $ad->getAllUsers();
            $liveUsers = $ad->filterValidUsers($rawAll);
        } catch (\Throwable $e) {
            $this->error('AD fetch failed — sync would ABORT and make no changes: ' . $e->getMessage());
            return self::FAILURE;
        }

        $liveAdIds     = collect($liveUsers)->pluck('id')->all();
        $disabledAdIds = collect($liveUsers)->filter(fn ($u) => ($u['accountEnabled'] ?? true) === false)->pluck('id')->all();
        $liveEmails    = collect($liveUsers)->map(fn ($u) => strtolower($u['mail'] ?? $u['userPrincipalName'] ?? ''))->filter()->values();
        $liveNames     = collect($liveUsers)->pluck('displayName')->map(fn ($n) => strtolower(trim($n)));

        $this->line(sprintf('raw users: %d | valid (post-filter): %d | disabled: %d',
            count($rawAll), count($liveUsers), count($disabledAdIds)));

        // Was this GUID dropped by filterValidUsers rather than truly absent from AD?
        if ($employee->ad_object_id) {
            $inRaw   = collect($rawAll)->firstWhere('id', $employee->ad_object_id) !== null;
            $inValid = in_array($employee->ad_object_id, $liveAdIds, true);
            if ($inRaw && !$inValid) {
                $this->warn('NOTE: this ad_object_id IS returned by AD but was DROPPED by filterValidUsers() '
                    . '(onmicrosoft/blank name/etc). It is treated as "removed" even though the account still exists.');
            }

            // Direct GUID probe for a definitive AD status.
            $probe = Http::withToken($token)->get(
                "https://graph.microsoft.com/v1.0/users/{$employee->ad_object_id}?\$select=id,accountEnabled,userPrincipalName"
            );
            $this->line('direct GET /users/{ad_object_id} => HTTP ' . $probe->status()
                . ($probe->successful() ? ' (accountEnabled=' . var_export($probe->json('accountEnabled'), true) . ')' : ' (gone from AD)'));
        }

        $this->newLine();
        $this->info('── DECISION (mirrors deactivateRemovedAdUsers) ──');

        // ── 3a. LINKED branch: ad_object_id IS set ──
        if (!empty($employee->ad_object_id)) {
            $isDisabled = in_array($employee->ad_object_id, $disabledAdIds, true);
            $isRemoved  = !in_array($employee->ad_object_id, $liveAdIds, true);

            $this->line('Path: LINKED (ad_object_id is set)');
            $this->line('  • flagged "disabled" (accountEnabled===false in AD): ' . $this->yn($isDisabled));
            $this->line('  • flagged "removed" (ad_object_id absent from live valid set): ' . $this->yn($isRemoved));

            return $this->verdict($isDisabled || $isRemoved, $employee, 'linked');
        }

        // ── 3b. UNLINKED branch: ad_object_id is NULL ──
        $this->line('Path: UNLINKED (ad_object_id is NULL)  <-- linked "removed" rule does NOT apply here');

        $typeOk  = $employee->employee_type === 'COSMOS';
        $notExcluded = !in_array($employee->name, $this->excludedNames, true);
        $emailAbsent = !$liveEmails->contains(strtolower($employee->email ?? ''));
        $nameAbsent  = !$liveNames->contains(strtolower(trim($employee->name ?? '')));

        $this->line('  • employee_type === "COSMOS": ' . $this->yn($typeOk) . '  (actual: ' . var_export($employee->employee_type, true) . ')');
        $this->line('  • name not in hard-coded exclusion list: ' . $this->yn($notExcluded));
        $this->line('  • email NOT found among live AD users: ' . $this->yn($emailAbsent));
        $this->line('  • name  NOT found among live AD users: ' . $this->yn($nameAbsent));

        $would = $typeOk && $notExcluded && $emailAbsent && $nameAbsent;
        return $this->verdict($would, $employee, 'unlinked');
    }

    private function verdict(bool $would, Employee $employee, string $path): int
    {
        $this->newLine();
        if ($would) {
            $this->info('VERDICT: this employee WOULD be soft-deleted by the sync.');
            $this->warn('=> If the client says it is NOT being deleted, the cause is likely ORG SCOPING or that the cleanup was never triggered:');
        } else {
            $this->error('VERDICT: this employee would NOT be soft-deleted — one of the checks above blocks it. That is the bug.');
        }

        $this->newLine();
        $this->line('Org-scoping check (the sync uses the ACTING ADMIN\'s org, not the employee\'s):');
        $this->line("  employee.organization_id = {$employee->organization_id}");
        $this->line('  The admin who clicks "Delete Removed" must belong to THIS org, or the query never selects this row.');

        return self::SUCCESS;
    }

    private function yn(bool $b): string
    {
        return $b ? 'YES' : 'no';
    }
}
