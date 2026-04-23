<?php
/**
 * SPECIAL ACTIVITIES — Livewire Volt Page
 * File: resources/views/livewire/pages/special-activities/index.blade.php
 *
 * Route: Route::get('/special-activities', SpecialActivitiesPage::class)->name('special-activities.index');
 */

use App\Models\Employee;
use App\Models\SpecialActivity;
use App\Models\SpecialActivityParticipant;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    // ── Wizard state ─────────────────────────────────────────────
    public int $step = 1;   // 1 = Activity Details, 2 = Grades & Dates, 3 = Participants
    public string $activeTab = 'all'; // all | live | upcoming | past

    // ── Step 1 fields ────────────────────────────────────────────
    public string $activityType = '';
    public string $activityName = '';
    public string $destination = '';

    // ── Step 2 fields ────────────────────────────────────────────
    public string $activityDate = '';
    public string $departureTime = '08:00';
    public string $returnTime = '15:00';
    public string $emergencyContact = '';
    public array $eligibleGrades = [];
    public string $notes = '';

    // ── Step 3 fields ────────────────────────────────────────────
    public string $participantSearch = '';
    public array $searchResults = [];
    public array $participants = []; // [['id'=>1,'name'=>'...','grade'=>'...','id_number'=>'...']]
    public string $bulkGrade = '';

    // ── Summary stats ────────────────────────────────────────────
    public int $totalActivities = 0;
    public int $liveCount = 0;
    public int $upcomingCount = 0;
    public int $studentsOut = 0;

    // ── Activity list ────────────────────────────────────────────
    public array $activities = [];
    public ?array $featuredActivity = null; // The live/latest activity for the participant table

    // ── Available grades (from org) ──────────────────────────────
    public array $availableGrades = [];

    // ── Edit mode ────────────────────────────────────────────────
    public ?int $editId = null;

    public array $activityTypes = [
        'field_trip' => ['label' => 'Field Trip', 'emoji' => '🏛️'],
        'sports' => ['label' => 'Sports', 'emoji' => '⚽'],
        'cultural' => ['label' => 'Cultural', 'emoji' => '🎭'],
        'academic' => ['label' => 'Academic', 'emoji' => '📐'],
        'community' => ['label' => 'Community', 'emoji' => '🤝'],
        'other' => ['label' => 'Other', 'emoji' => '🎒'],
    ];


    public bool $showModal = false;

    public string $endDate = '';   // ← add this line
    public ?int $selectedActivityId = null;


    public function openModal(): void
    {
        $this->resetWizard();
        $this->showModal = true;
    }

    public function mount(): void
    {
        $orgId = auth()->user()->employee->organization_id;

        $this->availableGrades = Employee::where('organization_id', $orgId)
            ->where('is_student', 1)
            ->where('active', 1)
            ->whereNotNull('grade')
            ->distinct()
            ->orderBy('grade')
            ->pluck('grade')
            ->toArray();

        $this->activityDate = now()->toDateString();
        $this->endDate = now()->toDateString();  // ← add alongside activityDate

        $this->loadStats();
        $this->loadActivities();
    }

    public function loadStats(): void
    {
        $orgId = auth()->user()->employee->organization_id;
        $now = now();

        $query = SpecialActivity::where('organization_id', $orgId);

        $this->totalActivities = (clone $query)->whereYear('activity_date', $now->year)->count();
        $this->liveCount = (clone $query)->where('activity_date', $now->toDateString())
            ->where('departure_time', '<=', $now->format('H:i'))
            ->where('return_time', '>=', $now->format('H:i'))
            ->count();

        // WITH:
        $this->upcomingCount = (clone $query)
            ->where(function ($q) use ($now) {
                $q->where('activity_date', '>', $now->toDateString())
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('activity_date', $now->toDateString())
                            ->where('departure_time', '>', $now->format('H:i'));
                    });
            })->count();

        // Students currently off-campus on a live activity
        $liveIds = SpecialActivity::where('organization_id', $orgId)
            ->where('activity_date', '<=', $now->toDateString())
            ->where(function ($q) use ($now) {
                $q->where(function ($q2) use ($now) {
                    // Single-day: must be today
                    $q2->whereNull('end_date')
                        ->where('activity_date', $now->toDateString());
                })->orWhere(function ($q2) use ($now) {
                    // Multi-day: today falls within range
                    $q2->whereNotNull('end_date')
                        ->where('end_date', '>=', $now->toDateString());
                });
            })
            ->where('departure_time', '<=', $now->format('H:i'))
            ->where('return_time', '>=', $now->format('H:i'))
            ->pluck('id');

        $this->studentsOut = SpecialActivityParticipant::whereIn('special_activity_id', $liveIds)
            ->where('status', 'departed')  // ← only actually off-campus
            ->count();
    }

    // Replace loadActivities() — just add selectedActivityId defaulting logic at the end
    public function loadActivities(): void
    {
        $orgId = auth()->user()->employee->organization_id;
        $now = now();

        $query = SpecialActivity::with(['participants.employee'])
            ->where('organization_id', $orgId)
            ->orderByDesc('activity_date');

        if ($this->activeTab === 'live') {
            $query->where('activity_date', $now->toDateString())
                ->where('departure_time', '<=', $now->format('H:i'))
                ->where('return_time', '>=', $now->format('H:i'));
        } elseif ($this->activeTab === 'upcoming') {
            $query->where(function ($q) use ($now) {
                $q->where('activity_date', '>', $now->toDateString())
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('activity_date', $now->toDateString())
                            ->where('departure_time', '>', $now->format('H:i'));
                    });
            });
        } elseif ($this->activeTab === 'past') {
            $query->where('activity_date', '<', $now->toDateString());
        }

        $all = $query->get();
        $this->activities = $all->map(fn($a) => $this->mapActivity($a))->toArray();

        // Auto-select: prefer live, then upcoming, then first in list
        // But don't override if user already picked one that still exists
        $existingIds = $all->pluck('id')->toArray();
        if (!$this->selectedActivityId || !in_array($this->selectedActivityId, $existingIds)) {
            $featured = $all->first(fn($a) => $this->resolveStatus($a) === 'live')
                ?? $all->first(fn($a) => $this->resolveStatus($a) === 'upcoming')
                ?? $all->first();
            $this->selectedActivityId = $featured?->id;
        }

        // Build featuredActivity from selectedActivityId
        $selected = $all->firstWhere('id', $this->selectedActivityId);
        $this->featuredActivity = $selected ? $this->mapActivity($selected) : null;
    }


    // Add this new method
    public function selectActivity(int $id): void
    {
        $this->selectedActivityId = $id;
        $orgId = auth()->user()->employee->organization_id;

        $activity = SpecialActivity::with(['participants.employee'])
            ->where('organization_id', $orgId)
            ->find($id);

        $this->featuredActivity = $activity ? $this->mapActivity($activity) : null;
    }

    private function resolveStatus(SpecialActivity $a): string
    {
        $now = now();
        $startDate = \Carbon\Carbon::parse($a->activity_date)->toDateString();
        $endDate = $a->end_date
            ? \Carbon\Carbon::parse($a->end_date)->toDateString()
            : $startDate;

        $todayStr = $now->toDateString();

        // Before start
        if ($todayStr < $startDate) return 'upcoming';

        // After end
        if ($todayStr > $endDate) return 'completed';

        // Within date range — check time window on first and last day
        if ($todayStr === $startDate) {
            $dep = \Carbon\Carbon::parse($startDate . ' ' . $a->departure_time);
            if ($now->lt($dep)) return 'upcoming';
        }

        if ($todayStr === $endDate) {
            $ret = \Carbon\Carbon::parse($endDate . ' ' . $a->return_time);
            if ($now->gt($ret)) return 'completed';
        }

        return 'live';
    }


    private function initials(string $name): string
    {
        $words = array_filter(explode(' ', trim($name))); // filter removes empty segments
        $words = array_slice(array_values($words), 0, 2);
        return strtoupper(implode('', array_map(fn($w) => $w[0], $words)));
    }

    private function mapActivity(SpecialActivity $a): array
    {
        $status = $this->resolveStatus($a);
        $participantCount = $a->participants->count();
        $grades = $a->eligible_grades ?? [];

        return [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'typeLabel' => $this->activityTypes[$a->type]['label'] ?? ucfirst($a->type),
            'typeEmoji' => $this->activityTypes[$a->type]['emoji'] ?? '📌',
            'destination' => $a->destination,
            'date' => \Carbon\Carbon::parse($a->activity_date)->format('D d M Y')
                . ($a->end_date && $a->end_date !== $a->activity_date
                    ? ' → ' . \Carbon\Carbon::parse($a->end_date)->format('D d M Y')
                    : ''),
            'dateRaw' => $a->activity_date,
            'timeRange' => \Carbon\Carbon::parse($a->departure_time)->format('h:i A') . ' — ' . \Carbon\Carbon::parse($a->return_time)->format('h:i A'),
            'transport' => $a->transport ?? null,
            'lead' => $a->lead_staff ?? null,
            'grades' => $grades,
            'participants' => $participantCount,
            'status' => $status,
            'notes' => $a->notes,
            'participantList' => $a->participants->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->employee?->name ?? '—',
                'grade' => $p->employee?->grade ?? '—',
                'idNum' => $p->employee?->id_number ?? '—',
                'status' => $p->status,
                'departed' => $p->departed_at ? \Carbon\Carbon::parse($p->departed_at)->format('h:i A') : null,
                'returned' => $p->returned_at ? \Carbon\Carbon::parse($p->returned_at)->format('h:i A') : null,
                'expected' => \Carbon\Carbon::parse($a->return_time)->format('h:i A'),
                'initials' => $this->initials($p->employee?->name ?? 'NA'),
                'color' => $this->avatarColor($p->employee?->name ?? ''),
            ])->toArray(),
        ];
    }

    private function avatarColor(string $name): string
    {
        $colors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899'];
        return $colors[abs(crc32($name)) % count($colors)];
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->loadActivities();
    }

    // ── Wizard navigation ─────────────────────────────────────────
    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validateStep1();
            $this->step = 2;
        } elseif ($this->step === 2) {
            $this->validateStep2();
            $this->step = 3;
        }
    }

    public function prevStep(): void
    {
        if ($this->step > 1) $this->step--;
    }

    private function validateStep1(): void
    {
        $this->validate([
            'activityType' => 'required|string',
            'activityName' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
        ], [
            'activityType.required' => 'Please select an activity type.',
            'activityName.required' => 'Activity name is required.',
            'destination.required' => 'Destination / Venue is required.',
        ]);
    }

    private function validateStep2(): void
    {
        $this->validate([
            'activityDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:activityDate', // ← add
            'departureTime' => 'required',
            'returnTime' => 'required',
            'eligibleGrades' => 'required|array|min:1',
        ], [
            'eligibleGrades.required' => 'Please select at least one grade.',
            'eligibleGrades.min' => 'Please select at least one grade.',
        ]);
    }

    // ── Participant search ────────────────────────────────────────
    public function updatedParticipantSearch(): void
    {
        if (strlen($this->participantSearch) < 2) {
            $this->searchResults = [];
            return;
        }

        $orgId = auth()->user()->employee->organization_id;
        $addedIds = array_column($this->participants, 'id');

        $results = Employee::where('organization_id', $orgId)
            ->where('is_student', 1)
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('name', 'like', "%{$this->participantSearch}%")
                    ->orWhere('id_number', 'like', "%{$this->participantSearch}%");
            })
            ->when(!empty($addedIds), fn($q) => $q->whereNotIn('id', $addedIds))
            ->when(!empty($this->eligibleGrades), fn($q) => $q->whereIn('grade', $this->eligibleGrades))
            ->limit(8)
            ->get(['id', 'name', 'grade', 'id_number']);

        $this->searchResults = $results->map(fn($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'grade' => $e->grade,
            'id_number' => $e->id_number,
            'initials' => $this->initials($e->name),
            'color' => $this->avatarColor($e->name),
        ])->toArray();
    }

    public function addParticipant(int $id): void
    {
        if (collect($this->participants)->pluck('id')->contains($id)) return;
        $match = collect($this->searchResults)->firstWhere('id', $id);
        if ($match) {
            $this->participants[] = $match;
            $this->participantSearch = '';
            $this->searchResults = [];
        }
    }

    public function removeParticipant(int $id): void
    {
        $this->participants = array_values(
            array_filter($this->participants, fn($p) => $p['id'] !== $id)
        );
    }

    public function addByGrade(): void
    {
        if (!$this->bulkGrade) return;

        $orgId = auth()->user()->employee->organization_id;
        $addedIds = array_column($this->participants, 'id');

        $students = Employee::where('organization_id', $orgId)
            ->where('is_student', 1)
            ->where('active', 1)
            ->where('grade', $this->bulkGrade)
            ->whereNotIn('id', $addedIds)
            ->get(['id', 'name', 'grade', 'id_number']);

        foreach ($students as $s) {
            $this->participants[] = [
                'id' => $s->id,
                'name' => $s->name,
                'grade' => $s->grade,
                'id_number' => $s->id_number,
                'initials' => $this->initials($s->name),
                'color' => $this->avatarColor($s->name),
            ];
        }
        $this->bulkGrade = '';
    }

    // ── Save activity ─────────────────────────────────────────────
    public function createActivity(): void
    {
        $this->validateStep2();

        if (empty($this->participants)) {
            LivewireAlert::title('No Participants')
                ->text('Please add at least one participant.')
                ->warning()->toast()->position('top-end')->show();
            return;
        }

        try {
            DB::beginTransaction();

            $org = auth()->user()->employee->organization;

            $activity = SpecialActivity::create([
                'organization_id' => $org->id,
                'name' => $this->activityName,
                'type' => $this->activityType,
                'destination' => $this->destination,
                'activity_date' => $this->activityDate,
                'end_date' => $this->endDate !== $this->activityDate ? $this->endDate : null, // ← add
                'departure_time' => $this->departureTime,
                'return_time' => $this->returnTime,
                'emergency_contact' => $this->emergencyContact ?: null,
                'eligible_grades' => $this->eligibleGrades,
                'notes' => $this->notes ?: null,
                'created_by' => auth()->id(),
            ]);

            foreach ($this->participants as $p) {
                SpecialActivityParticipant::create([
                    'special_activity_id' => $activity->id,
                    'employee_id' => $p['id'],
                    'status' => 'confirmed',
                ]);
            }

            DB::commit();

            $this->showModal = false;
            $this->resetWizard();
            $this->dispatch('hide-activity-modal');
            $this->loadStats();
            $this->loadActivities();

            LivewireAlert::title('Activity Created!')
                ->text('Special activity has been saved with ' . count($this->participants) . ' participants.')
                ->success()->toast()->position('top-end')->show();

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            LivewireAlert::title('Error!')->text($e->getMessage())->error()->toast()->position('top-end')->show();
        }
    }

    private function resetWizard(): void
    {
        $this->step = 1;
        $this->activityType = '';
        $this->activityName = '';
        $this->destination = '';
        $this->activityDate = now()->toDateString();
        $this->departureTime = '08:00';
        $this->returnTime = '15:00';
        $this->emergencyContact = '';
        $this->eligibleGrades = [];
        $this->notes = '';
        $this->participants = [];
        $this->participantSearch = '';
        $this->searchResults = [];
        $this->bulkGrade = '';
        $this->editId = null;
        $this->endDate = now()->toDateString();  // ← add alongside activityDate reset

    }

    public function discardModal(): void
    {
        $this->resetWizard();
        $this->showModal = false;
    }


    public function deleteActivity(int $id): void
    {
        try {
            $activity = SpecialActivity::where('organization_id', auth()->user()->employee->organization_id)
                ->findOrFail($id);

            // Cascade delete participants first, then the activity
            $activity->participants()->delete();
            $activity->delete();

            $this->loadStats();
            $this->loadActivities();

            LivewireAlert::title('Deleted!')
                ->text('Activity and all participant records removed.')
                ->success()->toast()->position('top-end')->show();

        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Could not delete activity.')
                ->error()->toast()->position('top-end')->show();
        }
    }

    // ── Biometric hook: called by ZKBio processing when a student scan occurs ──
    // This is wired up in ProcessZKBioTransactions job — when a student is
    // a participant in a live activity, their departure/return is tracked here.
    // The job should call: SpecialActivityParticipant::handleBiometricScan($employeeId, $orgId, $type)
    // where $type = 'clocked_in' | 'clocked_out'

};

?>

@push('styles')
    <style>
        /* ══════════════════════════════════════════════
           SPECIAL ACTIVITIES — Page Styles
           Matches the existing summary-card design system
        ══════════════════════════════════════════════ */

        .sa-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 1.5rem;
        }

        /* Stat cards */
        .sa-stat-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 1.25rem 1.4rem;
            height: 100%;
            transition: transform .2s, box-shadow .2s;
            position: relative;
            overflow: hidden;
        }

        .sa-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, .08);
        }

        .sa-stat-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: .8rem;
        }

        .sa-stat-card-label {
            font-size: .7rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .sa-stat-card-value {
            font-size: 1.9rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.1;
        }

        .sa-stat-card-sub {
            font-size: .78rem;
            color: #64748b;
            margin-top: 2px;
        }

        .sa-stat-live-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
            margin-right: 5px;
            animation: pulse-dot 1.4s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% {
                opacity: 1;
                transform: scale(1)
            }
            50% {
                opacity: .5;
                transform: scale(1.3)
            }
        }

        /* Tab bar */
        .sa-tab-bar {
            display: flex;
            gap: 4px;
            background: #f1f5f9;
            border-radius: 10px;
            padding: 4px;
        }

        .sa-tab-btn {
            padding: 6px 16px;
            border-radius: 7px;
            border: none;
            background: transparent;
            font-size: .82rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all .15s;
        }

        .sa-tab-btn.active {
            background: #fff;
            color: #1e293b;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .08);
        }

        /* Activity cards */
        .sa-activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .sa-activity-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, .06);
            border-radius: 14px;
            padding: 1.25rem;
            transition: transform .2s, box-shadow .2s;
            position: relative;
            overflow: hidden;
        }

        .sa-activity-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .1);
        }

        .sa-activity-card.is-live {
            border-color: #bbf7d0;
        }

        .sa-activity-card.is-live::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #22c55e;
        }

        .sa-card-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 99px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .sa-badge-live {
            background: #dcfce7;
            color: #15803d;
        }

        .sa-badge-upcoming {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .sa-badge-completed {
            background: #f1f5f9;
            color: #64748b;
        }

        .sa-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin: .5rem 0 .2rem;
        }

        .sa-card-meta {
            font-size: .78rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 3px;
        }

        .sa-card-meta iconify-icon {
            font-size: 14px;
            flex-shrink: 0;
        }

        .sa-grade-chip {
            display: inline-block;
            font-size: .68rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
        }

        .sa-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: .9rem;
            padding-top: .7rem;
            border-top: 1px solid #f1f5f9;
        }

        .sa-card-students {
            font-size: .8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .sa-view-btn {
            font-size: .78rem;
            font-weight: 600;
            color: #6366f1;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
        }

        .sa-view-btn:hover {
            text-decoration: underline;
        }

        /* Participant table */
        .sa-participants-panel {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, .06);
            border-radius: 14px;
            overflow: hidden;
        }

        .sa-participants-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .sa-participants-title {
            font-size: .95rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .sa-participant-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 1.25rem;
            border-bottom: 1px solid #f8fafc;
            font-size: .83rem;
        }

        .sa-participant-row:last-child {
            border-bottom: none;
        }

        .sa-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .72rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .sa-p-name {
            font-weight: 600;
            color: #1e293b;
        }

        .sa-p-sub {
            font-size: .72rem;
            color: #94a3b8;
        }

        .sa-p-grade {
            background: #f1f5f9;
            color: #475569;
            font-size: .72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .sa-p-status {
            font-size: .72rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 99px;
            white-space: nowrap;
        }

        .sa-p-status.departed {
            background: #fef9c3;
            color: #a16207;
        }

        .sa-p-status.returned {
            background: #dcfce7;
            color: #15803d;
        }

        .sa-p-status.confirmed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .sa-p-time {
            font-size: .75rem;
            color: #64748b;
            white-space: nowrap;
        }

        /* ── WIZARD MODAL ─────────────────────────────────────── */
        .wizard-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            z-index: 1060;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .wizard-box {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 660px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .22);
            overflow: hidden;
        }

        .wizard-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 1.5rem 1.75rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .wizard-org-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 4px 10px;
            font-size: .75rem;
            color: #64748b;
            margin-bottom: 4px;
        }

        .wizard-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #1e293b;
            margin: 0;
        }

        .wizard-close-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            flex-shrink: 0;
        }

        .wizard-close-btn:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
        }

        /* Stepper */
        .wizard-steps {
            display: flex;
            align-items: center;
            gap: 0;
            padding: .9rem 1.75rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .wizard-step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .8rem;
            font-weight: 600;
            color: #94a3b8;
        }

        .wizard-step-item.active {
            color: #1e293b;
        }

        .wizard-step-item.done {
            color: #22c55e;
        }

        .wizard-step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .78rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #94a3b8;
            flex-shrink: 0;
            border: 2px solid #e2e8f0;
        }

        .wizard-step-item.active .wizard-step-circle {
            background: #1e293b;
            color: #fff;
            border-color: #1e293b;
        }

        .wizard-step-item.done .wizard-step-circle {
            background: #22c55e;
            color: #fff;
            border-color: #22c55e;
        }

        .wizard-step-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            margin: 0 8px;
            min-width: 40px;
        }

        .wizard-step-line.done {
            background: #22c55e;
        }

        /* Wizard body */
        .wizard-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 1.75rem;
        }

        .wizard-section-label {
            font-size: .7rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: .8rem;
        }

        /* Type grid */
        .type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 1.25rem;
        }

        .type-tile {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: .9rem .5rem;
            text-align: center;
            cursor: pointer;
            transition: all .15s;
            background: #fafafa;
        }

        .type-tile:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .type-tile.selected {
            border-color: #1e293b;
            background: #f8fafc;
            box-shadow: 0 0 0 3px rgba(30, 41, 59, .08);
        }

        .type-tile-emoji {
            font-size: 1.9rem;
            margin-bottom: 5px;
        }

        .type-tile-label {
            font-size: .78rem;
            font-weight: 600;
            color: #475569;
        }

        .type-tile.selected .type-tile-label {
            color: #1e293b;
        }

        /* Grades checkbox grid */
        .grade-check-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .grade-check-item {
            display: flex;
            align-items: center;
            gap: 0;
            cursor: pointer;
        }

        .grade-check-item input[type="checkbox"] {
            display: none;
        }

        .grade-check-label {
            padding: 7px 14px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            font-size: .8rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all .15s;
            user-select: none;
        }

        .grade-check-item input:checked + .grade-check-label {
            border-color: #1e293b;
            background: #1e293b;
            color: #fff;
        }

        /* Participant search */
        .p-search-wrap {
            position: relative;
        }

        .p-search-input {
            width: 100%;
            padding: 9px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: .85rem;
            outline: none;
            transition: border-color .15s;
        }

        .p-search-input:focus {
            border-color: #1e293b;
        }

        .p-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .1);
            z-index: 50;
            margin-top: 4px;
            max-height: 220px;
            overflow-y: auto;
        }

        .p-search-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            cursor: pointer;
            transition: background .1s;
        }

        .p-search-item:hover {
            background: #f8fafc;
        }

        /* Added participants list */
        .p-list {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
        }

        .p-list-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #f8fafc;
        }

        .p-list-item:last-child {
            border-bottom: none;
        }

        .p-remove-btn {
            margin-left: auto;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1rem;
            padding: 0 2px;
        }

        .p-remove-btn:hover {
            color: #ef4444;
        }

        /* Wizard footer */
        .wizard-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.75rem;
            border-top: 1px solid #f1f5f9;
            flex-shrink: 0;
        }

        .wizard-btn-cancel {
            font-size: .85rem;
            font-weight: 600;
            color: #64748b;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px 16px;
        }

        .wizard-btn-back {
            font-size: .85rem;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 9px 20px;
            cursor: pointer;
            transition: background .15s;
        }

        .wizard-btn-back:hover {
            background: #e2e8f0;
        }

        .wizard-btn-next {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .85rem;
            font-weight: 700;
            color: #fff;
            background: #1e293b;
            border: none;
            border-radius: 10px;
            padding: 9px 22px;
            cursor: pointer;
            transition: background .15s;
        }

        .wizard-btn-next:hover {
            background: #0f172a;
        }

        .wizard-btn-create {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .85rem;
            font-weight: 700;
            color: #1e293b;
            background: #fbbf24;
            border: none;
            border-radius: 10px;
            padding: 9px 22px;
            cursor: pointer;
            transition: background .15s;
        }

        .wizard-btn-create:hover {
            background: #f59e0b;
        }

        .wi-form-label {
            font-size: .8rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
            display: block;
        }

        .wi-form-control {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: .85rem;
            color: #1e293b;
            outline: none;
            transition: border-color .15s;
            background: #fff;
        }

        .wi-form-control:focus {
            border-color: #1e293b;
        }

        .wi-error {
            font-size: .75rem;
            color: #dc2626;
            margin-top: 3px;
        }

        .add-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .82rem;
            font-weight: 700;
            color: #fff;
            background: #1e293b;
            border: none;
            border-radius: 9px;
            padding: 9px 16px;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }

        .add-btn:hover {
            background: #0f172a;
        }

        .sa-delete-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .75rem;
            font-weight: 600;
            color: #dc2626;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            transition: opacity .15s;
        }

        .sa-delete-btn:hover {
            opacity: .7;
        }

        .sa-view-btn-active {
            color: #15803d;
            font-weight: 700;
        }

    </style>
@endpush

<div>
    <div class="row">
        <div class="col-12">

            {{-- Breadcrumb --}}
            @php
                $bcItems = [
                    ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>'],
                    ['label' => 'Special Activities', 'url' => '#', 'icon' => '<iconify-icon icon="mdi:bus-school" class="fs-5"></iconify-icon>'],
                ];
            @endphp
            <livewire:admin.system-settings.bread-crumb title="Special Activities" :items="$bcItems"/>

            {{-- Page header --}}
            <div class="sa-header">
                <div>
                    <h5 class="fw-bold mb-0" style="color:#1e293b;">Special Activities</h5>
                    <p class="text-muted small mb-0">Track field trips, sports days, and off-campus events</p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <button class="btn btn-primary btn-sm d-flex align-items-center gap-1"
                            style="height:38px; background:#1e293b; border-color:#1e293b;"
                            wire:click="openModal">
                        <iconify-icon icon="mdi:plus" style="font-size:16px;"></iconify-icon>
                        New Activity
                    </button>
                </div>
            </div>

            {{-- Summary stat cards --}}
            <div class="row g-3 mb-4">
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="sa-stat-card">
                        <div class="sa-stat-card-icon" style="background:#ede9fe; color:#7c3aed;">
                            <iconify-icon icon="mdi:calendar-check"></iconify-icon>
                        </div>
                        <p class="sa-stat-card-label">Total Activities</p>
                        <div class="sa-stat-card-value">{{ $totalActivities }}</div>
                        <p class="sa-stat-card-sub">This term</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="sa-stat-card">
                        <div class="sa-stat-card-icon" style="background:#dcfce7; color:#15803d;">
                            <iconify-icon icon="mdi:antenna"></iconify-icon>
                        </div>
                        <p class="sa-stat-card-label">Live Now</p>
                        <div class="sa-stat-card-value">
                            @if($liveCount > 0)
                                <span class="sa-stat-live-dot"></span>
                            @endif
                            {{ $liveCount }}
                        </div>
                        <p class="sa-stat-card-sub">Currently in progress</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="sa-stat-card">
                        <div class="sa-stat-card-icon" style="background:#dbeafe; color:#1d4ed8;">
                            <iconify-icon icon="mdi:calendar-clock"></iconify-icon>
                        </div>
                        <p class="sa-stat-card-label">Upcoming</p>
                        <div class="sa-stat-card-value">{{ $upcomingCount }}</div>
                        <p class="sa-stat-card-sub">Scheduled ahead</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="sa-stat-card">
                        <div class="sa-stat-card-icon" style="background:#fef9c3; color:#a16207;">
                            <iconify-icon icon="mdi:bus-school"></iconify-icon>
                        </div>
                        <p class="sa-stat-card-label">Students Out</p>
                        <div class="sa-stat-card-value">{{ $studentsOut }}</div>
                        <p class="sa-stat-card-sub">Currently off-campus</p>
                    </div>
                </div>
            </div>

            {{-- Tab bar + Activity grid --}}
            <div class="card card-body mb-4" style="border-radius:14px; border:1px solid rgba(0,0,0,.06);">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div class="sa-tab-bar">
                        @foreach(['all' => 'All', 'live' => '🟢 Live', 'upcoming' => 'Upcoming', 'past' => 'Past'] as $key => $label)
                            <button class="sa-tab-btn {{ $activeTab === $key ? 'active' : '' }}"
                                    wire:click="setTab('{{ $key }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm"
                                style="width:130px; font-size:.82rem; border-radius:8px;">
                            <option>All Types</option>
                            @foreach($activityTypes as $key => $t)
                                <option value="{{ $key }}">{{ $t['label'] }}</option>
                            @endforeach
                        </select>
                        <select class="form-select form-select-sm"
                                style="width:140px; font-size:.82rem; border-radius:8px;">
                            <option>All Grades</option>
                            @foreach($availableGrades as $g)
                                <option>{{ $g }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if(count($activities) === 0)
                    <div class="text-center py-5">
                        <iconify-icon icon="mdi:calendar-blank-outline"
                                      style="font-size:3rem; color:#cbd5e1;"></iconify-icon>
                        <p class="text-muted mt-2 mb-0">No activities found.</p>
                    </div>
                @else
                    <div class="sa-activity-grid">
                        @foreach($activities as $act)
                            <div class="sa-activity-card {{ $act['status'] === 'live' ? 'is-live' : '' }}">
                                {{-- Status badge --}}
                                <div class="d-flex align-items-center justify-content-between">
                                <span class="sa-card-status-badge sa-badge-{{ $act['status'] }}">
                                    @if($act['status'] === 'live')
                                        <span class="sa-stat-live-dot"
                                              style="width:6px;height:6px;margin-right:3px;"></span>
                                    @endif
                                    {{ ucfirst($act['status']) }}
                                </span>
                                    <span style="font-size:1.3rem;">{{ $act['typeEmoji'] }}</span>
                                </div>

                                <h6 class="sa-card-title">{{ $act['name'] }}</h6>

                                <div class="sa-card-meta">
                                    <iconify-icon icon="mdi:calendar"></iconify-icon>
                                    {{ $act['date'] }} &middot; {{ $act['timeRange'] }}
                                </div>
                                <div class="sa-card-meta">
                                    <iconify-icon icon="mdi:map-marker"></iconify-icon>
                                    {{ $act['destination'] }}
                                </div>
                                @if($act['lead'])
                                    <div class="sa-card-meta">
                                        <iconify-icon icon="mdi:account-tie"></iconify-icon>
                                        {{ $act['lead'] }} (Lead)
                                    </div>
                                @endif

                                {{-- Grade chips --}}
                                @if(count($act['grades']))
                                    <div class="mt-2 d-flex flex-wrap gap-1">
                                        @foreach($act['grades'] as $g)
                                            <span class="sa-grade-chip">{{ $g }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="sa-card-footer">
                                <span class="sa-card-students">
                                    <iconify-icon icon="mdi:account-group"></iconify-icon>
                                    {{ $act['participants'] }} students
                                </span>
                                    <div class="d-flex align-items-center gap-2">
                                        <button
                                            class="sa-view-btn {{ $selectedActivityId === $act['id'] ? 'sa-view-btn-active' : '' }}"
                                            wire:click="selectActivity({{ $act['id'] }})">
                                            {{ $selectedActivityId === $act['id'] ? '👁 Viewing' : 'View Students' }}
                                        </button>
                                        <button class="sa-delete-btn"
                                                wire:click="deleteActivity({{ $act['id'] }})"
                                                wire:confirm="Delete '{{ $act['name'] }}'? This will also remove all {{ $act['participants'] }} participant records.">
                                            <iconify-icon icon="mdi:trash-can-outline"
                                                          style="font-size:14px;"></iconify-icon>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Featured activity participant table (live or next upcoming) --}}
            @if($featuredActivity && count($featuredActivity['participantList']) > 0)
                <div class="sa-participants-panel mb-4">
                    <div class="sa-participants-header">
                        <div>
                            <h6 class="sa-participants-title">
                                {{ $featuredActivity['name'] }} — Participants
                                <span class="sa-card-status-badge sa-badge-{{ $featuredActivity['status'] }} ms-2"
                                      style="font-size:.65rem;">
                            {{ ucfirst($featuredActivity['status']) }}
                        </span>
                            </h6>
                            {{-- Activity meta line --}}
                            <p style="font-size:.75rem; color:#94a3b8; margin:4px 0 0;">
                                <iconify-icon icon="mdi:map-marker" style="font-size:13px;"></iconify-icon>
                                {{ $featuredActivity['destination'] }}
                                &nbsp;·&nbsp;
                                <iconify-icon icon="mdi:calendar" style="font-size:13px;"></iconify-icon>
                                {{ $featuredActivity['date'] }}
                                &nbsp;·&nbsp;
                                <iconify-icon icon="mdi:clock-outline" style="font-size:13px;"></iconify-icon>
                                {{ $featuredActivity['timeRange'] }}
                            </p>
                        </div>
                        {{-- Participant status summary --}}
                        <div class="d-flex gap-2">
                            @php
                                $pList = $featuredActivity['participantList'];
                                $confirmedCount = count(array_filter($pList, fn($p) => $p['status'] === 'confirmed'));
                                $departedCount  = count(array_filter($pList, fn($p) => $p['status'] === 'departed'));
                                $returnedCount  = count(array_filter($pList, fn($p) => $p['status'] === 'returned'));
                            @endphp
                            @if($confirmedCount)
                                <span class="sa-p-status confirmed">{{ $confirmedCount }} Confirmed</span>
                            @endif
                            @if($departedCount)
                                <span class="sa-p-status departed">{{ $departedCount }} Out</span>
                            @endif
                            @if($returnedCount)
                                <span class="sa-p-status returned">{{ $returnedCount }} Returned</span>
                            @endif
                        </div>
                    </div>

                    {{-- Table header --}}
                    <div class="sa-participant-row"
                         style="background:#f8fafc; font-size:.72rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px;">
                        <div style="width:24px; color:#cbd5e1;">#</div>
                        <div style="width:34px;"></div>
                        <div style="flex:2;">Student</div>
                        <div style="flex:1;">Grade</div>
                        <div style="flex:1;">Status</div>
                        <div style="flex:1;">Departed</div>
                        <div style="flex:1;">Expected Return</div>
                    </div>

                    @foreach(array_slice($featuredActivity['participantList'], 0, 10) as $i => $p)
                        <div class="sa-participant-row">
                            <div
                                style="width:24px; font-size:.72rem; color:#94a3b8;">{{ str_pad($i + 1, 3, '0', STR_PAD_LEFT) }}</div>
                            <div class="sa-avatar" style="background:{{ $p['color'] }};">{{ $p['initials'] }}</div>
                            <div style="flex:2;">
                                <div class="sa-p-name">{{ $p['name'] }}</div>
                                <div class="sa-p-sub">{{ $p['idNum'] }}</div>
                            </div>
                            <div style="flex:1;"><span class="sa-p-grade">{{ $p['grade'] }}</span></div>
                            <div style="flex:1;"><span
                                    class="sa-p-status {{ $p['status'] }}">{{ ucfirst($p['status']) }}</span></div>
                            <div style="flex:1;" class="sa-p-time">{{ $p['departed'] ?? '—' }}</div>
                            <div style="flex:1;" class="sa-p-time">{{ $p['expected'] }}</div>
                        </div>
                    @endforeach

                    @if(count($featuredActivity['participantList']) > 10)
                        <div class="text-center py-2" style="border-top:1px solid #f1f5f9;">
                <span class="school-panel-link" style="font-size:.8rem;">
                    Showing 1–10 of {{ count($featuredActivity['participantList']) }} participants
                </span>
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════
         NEW ACTIVITY WIZARD MODAL
         (Pure Livewire — no Bootstrap modal dependency)
    ════════════════════════════════════════════════════════ --}}
    @if($showModal)
        <div id="activityModalBackdrop" class="wizard-overlay" wire:ignore.self>

            <div class="wizard-box" @click.away="document.getElementById('activityModalBackdrop').style.display='none'">

                {{-- Top: title + close --}}
                <div class="wizard-top">
                    <div>
                        <div class="wizard-org-chip">
                            <iconify-icon icon="mdi:school"></iconify-icon>
                            {{ auth()->user()->employee->organization->name ?? 'Pembroke House' }} &middot;
                            {{ now()->year }}–{{ now()->addYear()->year }}
                        </div>
                        <h5 class="wizard-title">New Special Activity</h5>
                    </div>
                    <button class="wizard-close-btn" wire:click="discardModal">
                        ✕
                    </button>
                </div>

                {{-- Stepper --}}
                <div class="wizard-steps">
                    {{-- Step 1 --}}
                    <div class="wizard-step-item {{ $step === 1 ? 'active' : ($step > 1 ? 'done' : '') }}">
                        <div class="wizard-step-circle">
                            @if($step > 1)
                                <iconify-icon icon="mdi:check" style="font-size:14px;"></iconify-icon>
                            @else
                                1
                            @endif
                        </div>
                        Activity Details
                    </div>

                    <div class="wizard-step-line {{ $step > 1 ? 'done' : '' }}"></div>

                    {{-- Step 2 --}}
                    <div class="wizard-step-item {{ $step === 2 ? 'active' : ($step > 2 ? 'done' : '') }}">
                        <div class="wizard-step-circle">
                            @if($step > 2)
                                <iconify-icon icon="mdi:check" style="font-size:14px;"></iconify-icon>
                            @else
                                2
                            @endif
                        </div>
                        Grades & Dates
                    </div>

                    <div class="wizard-step-line {{ $step > 2 ? 'done' : '' }}"></div>

                    {{-- Step 3 --}}
                    <div class="wizard-step-item {{ $step === 3 ? 'active' : '' }}">
                        <div class="wizard-step-circle">3</div>
                        Participants
                    </div>
                </div>

                {{-- Body --}}
                <div class="wizard-body">

                    {{-- ── STEP 1: Activity Details ─────────────────── --}}
                    @if($step === 1)
                        <p class="wizard-section-label">Activity Type <span style="color:#ef4444;">*</span></p>
                        <div class="type-grid mb-4">
                            @foreach($activityTypes as $key => $t)
                                <div class="type-tile {{ $activityType === $key ? 'selected' : '' }}"
                                     wire:click="$set('activityType', '{{ $key }}')">
                                    <div class="type-tile-emoji">{{ $t['emoji'] }}</div>
                                    <div class="type-tile-label">{{ $t['label'] }}</div>
                                </div>
                            @endforeach
                        </div>
                        @error('activityType') <p class="wi-error mb-3">{{ $message }}</p> @enderror

                        <p class="wizard-section-label">Basic Information</p>

                        <div class="mb-3">
                            <label class="wi-form-label">Activity Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" wire:model="activityName" class="wi-form-control"
                                   placeholder="e.g. Science Museum Trip"/>
                            @error('activityName') <p class="wi-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="wi-form-label">Destination / Venue <span
                                    style="color:#ef4444;">*</span></label>
                            <input type="text" wire:model="destination" class="wi-form-control"
                                   placeholder="e.g. Nairobi National Museum"/>
                            @error('destination') <p class="wi-error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    {{-- ── STEP 2: Grades & Dates ───────────────────── --}}
                    @if($step === 2)
                        <p class="wizard-section-label">Date & Time <span style="color:#ef4444;">*</span></p>

                        <div class="row mb-3">
                            <div class="col-md-6 mb-3">
                                <label class="wi-form-label">Start Date <span style="color:#ef4444;">*</span></label>
                                <input type="date" wire:model="activityDate" class="wi-form-control"/>
                                @error('activityDate') <p class="wi-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="wi-form-label">End Date <span style="color:#ef4444;">*</span></label>
                                <input type="date" wire:model="endDate" class="wi-form-control"/>
                                @error('endDate') <p class="wi-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="wi-form-label">Departure Time <span
                                        style="color:#ef4444;">*</span></label>
                                <input type="time" wire:model="departureTime" class="wi-form-control"/>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="wi-form-label">Expected Return Time <span
                                        style="color:#ef4444;">*</span></label>
                                <input type="time" wire:model="returnTime" class="wi-form-control"/>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="wi-form-label">Emergency Contact (on-trip)</label>
                                <input type="text" wire:model="emergencyContact" class="wi-form-control"
                                       placeholder="+254 7XX XXX XXX"/>
                            </div>
                        </div>

                        <p class="wizard-section-label">Eligible Grades <span style="color:#ef4444;">*</span></p>
                        <div class="grade-check-grid mb-3">
                            @foreach($availableGrades as $g)
                                <label class="grade-check-item">
                                    <input type="checkbox" wire:model="eligibleGrades" value="{{ $g }}">
                                    <span class="grade-check-label">{{ $g }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('eligibleGrades') <p class="wi-error mb-2">{{ $message }}</p> @enderror

                        <p class="wizard-section-label mt-3">Additional Notes</p>
                        <div class="mb-2">
                            <label class="wi-form-label">Notes / Instructions</label>
                            <textarea wire:model="notes" class="wi-form-control" rows="3"
                                      placeholder="Any relevant info for staff, guardians, or students"></textarea>
                        </div>
                    @endif

                    {{-- ── STEP 3: Participants ─────────────────────── --}}
                    @if($step === 3)
                        <p class="wizard-section-label">Add Participants</p>

                        <div class="p-search-wrap mb-3">
                            <div class="d-flex gap-2">
                                <input type="text" wire:keyup="$dispatch('participant-search')"
                                       wire:model.live.debounce.300ms="participantSearch"
                                       class="p-search-input" placeholder="Search student by name or ID..."/>
                                <button class="add-btn" wire:click="updatedParticipantSearch">
                                    + Add
                                </button>
                            </div>

                            @if(count($searchResults))
                                <div class="p-search-results">
                                    @foreach($searchResults as $r)
                                        <div class="p-search-item" wire:click="addParticipant({{ $r['id'] }})">
                                            <div class="sa-avatar"
                                                 style="background:{{ $r['color'] }}; width:30px; height:30px; font-size:.65rem;">
                                                {{ $r['initials'] }}
                                            </div>
                                            <div>
                                                <div
                                                    style="font-size:.83rem; font-weight:600; color:#1e293b;">{{ $r['name'] }}</div>
                                                <div style="font-size:.72rem; color:#94a3b8;">{{ $r['grade'] }}
                                                    · {{ $r['id_number'] }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span style="font-size:.8rem; color:#64748b;">Added participants</span>
                            <span class="sa-grade-chip" style="background:#ede9fe; color:#7c3aed;">
                        {{ count($participants) }} students
                    </span>
                        </div>

                        @if(count($participants))
                            <div class="p-list mb-4">
                                @foreach($participants as $p)
                                    <div class="p-list-item">
                                        <div class="sa-avatar"
                                             style="background:{{ $p['color'] }}; width:30px; height:30px; font-size:.65rem;">
                                            {{ $p['initials'] }}
                                        </div>
                                        <div>
                                            <div
                                                style="font-size:.83rem; font-weight:600; color:#1e293b;">{{ $p['name'] }}</div>
                                            <div style="font-size:.72rem; color:#94a3b8;">{{ $p['grade'] }}
                                                · {{ $p['id_number'] }}</div>
                                        </div>
                                        <button class="p-remove-btn" wire:click="removeParticipant({{ $p['id'] }})">✕
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <p class="wizard-section-label">Bulk Add by Grade</p>
                        <div class="d-flex gap-2">
                            <select wire:model="bulkGrade" class="wi-form-control" style="flex:1;">
                                <option value="">Select a grade to add all</option>
                                @foreach($eligibleGrades as $g)
                                    <option value="{{ $g }}">{{ $g }}</option>
                                @endforeach
                            </select>
                            <button class="add-btn" wire:click="addByGrade">Add Grade</button>
                        </div>
                    @endif

                </div>

                {{-- Footer --}}
                <div class="wizard-footer">
                    <button class="wizard-btn-cancel" wire:click="discardModal">
                        Cancel
                    </button>

                    <div class="d-flex gap-2">
                        @if($step > 1)
                            <button class="wizard-btn-back" wire:click="prevStep">← Back</button>
                        @endif

                        @if($step < 3)
                            <button class="wizard-btn-next" wire:click="nextStep">
                                Continue
                                <iconify-icon icon="mdi:chevron-right" style="font-size:16px;"></iconify-icon>
                            </button>
                        @else
                            <button class="wizard-btn-create" wire:click="createActivity">
                                <iconify-icon icon="mdi:check"></iconify-icon>
                                Create Activity
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>

