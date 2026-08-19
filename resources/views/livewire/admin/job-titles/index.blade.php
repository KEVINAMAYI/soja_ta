<?php

use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Organization;
use App\Services\OrganizationHierarchyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public $organizationId;
    public string $search = '';
    public bool $showJobTitleModal = false;
    public ?int $editingJobTitleId = null;
    public bool $jobTitleActive = true;

    public $departments = null;

    public string $name = '';
    public string $description = '';
    public ?int $departmentId = null;
    public int $isActive = 1;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user && $user->employee && $user->employee->organization_id) {
            $this->organizationId = $user->employee->organization_id;
            $this->departments = Department::where('organization_id', $this->organizationId)->get();
            $this->org = Organization::findOrFail($this->organizationId);

            return;
        }

        abort(403, 'No organization found for this user.');
    }

    protected function jobTitlesQuery(): Builder
    {
        return JobTitle::query()
            ->with(['organization:id,name', 'createdBy:id,name', 'updatedBy:id,name'])
            ->where('organization_id', $this->organizationId);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'isActive' => ['required', 'integer', Rule::in([0, 1])],
            'departmentId' => ['required', 'integer', Rule::exists('departments', 'id')->where(function ($query) {
                $query->where('organization_id', $this->organizationId);
            })],
            'description' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function openAddJobTitleModal(): void
    {
        $this->resetForm();
        $this->showJobTitleModal = true;
        $this->dispatch('show-job-title-modal');
    }

    public function openEditJobTitleModal(int $id): void
    {
        $jobTitle = $this->jobTitlesQuery()->whereKey($id)->firstOrFail();

        $this->editingJobTitleId = $jobTitle->id;
        $this->name = $jobTitle->name;
        $this->description = $jobTitle->description;
        $this->departmentId = $jobTitle->department_id;
        $this->isActive = (int) $jobTitle->is_active;
        $this->jobTitleActive = (bool) $jobTitle->is_active;

        $this->showJobTitleModal = true;
        $this->dispatch('show-job-title-modal');
    }

    public function getJobTitlesHierarchyProperty(): array
    {
        $hierarchy = app(OrganizationHierarchyService::class)->build($this->organizationId); 

        //Log::info('Job Titles Hierarchy: ' . json_encode($hierarchy));
        return $this->flattenHierarchyRows($hierarchy);
    }

    protected function flattenHierarchyRows(array $hierarchy): array
    {
        $rows = [];

        foreach ($hierarchy['trees'] ?? [] as $rootNode) {
            $rootLevel = $this->calculateMaxDepthFromNode($rootNode);
            $this->appendHierarchyRow($rows, $rootNode, null, true, $rootLevel);
        }

        foreach ($hierarchy['dangling'] ?? [] as $danglingNode) {
            $this->appendHierarchyRow($rows, $danglingNode, 'dangling', false, 0);
        }

        $search = trim($this->search);

        if ($search === '') {
            return $rows;
        }

        $search = Str::lower($search);

        return array_values(array_filter($rows, function (array $row) use ($search) {
            return Str::contains(Str::lower((string) ($row['name'] ?? '')), $search)
                || Str::contains(Str::lower((string) ($row['description'] ?? '')), $search)
                || Str::contains(Str::lower((string) ($row['reports_to'] ?? '')), $search)
                || Str::contains(Str::lower((string) ($row['parent_title_name'] ?? '')), $search);
        }));
    }

    protected function appendHierarchyRow(array &$rows, array $node, ?string $parentTitleName, bool $isParent, int $level): void
    {
        $titleName = (string) ($node['name'] ?? 'N/A');
        $holders = (int) ($node['employee_count'] ?? count($node['employees'] ?? []));
        $effectiveLevel = max(0, (int) $level);

        $holder_name = $holders .'- Holders';
        if ($holders == 1) {
            $holder_name = isset($node['employees'][0]['employee_name']) ? $node['employees'][0]['employee_name'] : $holders .'- Holders';
        }

        $rows[] = [
            'id' => $node['id'] ?? null,
            'name' => $titleName,
            'description' => $node['description'] ?? null,
            'level' => $effectiveLevel,
            'reports_to' => $parentTitleName ?? '--top of chain --',
            'parent_title_name' => $parentTitleName,
            'employee_name' => $holder_name,
            'department_name' => $node['department_name'] ?? 'N/A',
            'holders' => $holders,
            'is_active' => (bool) ($node['is_active'] ?? false),
        ];

        $parentId = $node['parent_id'] ?? null;

        if ($parentId !== null && $parentId !== '') {
            foreach ($rows as $index => $row) {
                if (($row['id'] ?? null) == $parentId) {
                    if (isset($rows[$index]['direct_reporters_count'])) {
                        $rows[$index]['direct_reporters_count'] += $holders;
                        $rows[$index]['direct_reporters_title'] = 'employees';
                    } else {
                        $rows[$index]['direct_reporters_count'] = $holders;
                        $rows[$index]['direct_reporters_title'] = $titleName;
                    }
                    // $rows[$index]['direct_reporters_count'] = $holders;
                    // $rows[$index]['direct_reporters_title'] = $titleName;
                    // break;
                }
            }
        }

        foreach ($node['children'] ?? [] as $childNode) {
            $this->appendHierarchyRow($rows, $childNode, $titleName, false, $effectiveLevel - 1);
        }
    }

    private function calculateMaxDepthFromNode(array $node): int
    {
        $childDepths = [];

        foreach ($node['children'] ?? [] as $childNode) {
            $childDepths[] = $this->calculateMaxDepthFromNode($childNode);
        }

        if ($childDepths === []) {
            return 0;
        }

        return 1 + max($childDepths);
    }

    private function countAllChildren(array $node): int
    {
        $count = 0;

        foreach ($node['children'] ?? [] as $childNode) {
            $count += 1 + $this->countAllChildren($childNode);
        }

        return $count;
    }

    public function closeJobTitleModal(): void
    {
        $this->showJobTitleModal = false;
        $this->resetForm();
        $this->dispatch('hide-job-title-modal');
    }

    public function saveJobTitle(): void
    {
        $validated = $this->validate();

        $existingJobTitle = JobTitle::where('organization_id', $this->organizationId)
            ->where('name', $validated['name'])
            ->when($this->editingJobTitleId, function ($query) {
                $query->where('id', '!=', $this->editingJobTitleId);
            })
            ->first();

        if ($existingJobTitle) {
            throw ValidationException::withMessages([
                'name' => 'A job title with this name already exists for this organization.',
            ]);
        }



        DB::transaction(function () use ($validated) {
            if ($this->editingJobTitleId) {
                $jobTitle = $this->jobTitlesQuery()->whereKey($this->editingJobTitleId)->firstOrFail();

                $jobTitle->update([
                    'name' => $validated['name'],
                    'description' => $validated['description'],
                    'department_id' => $validated['departmentId'],
                    'is_active' => (bool) $validated['isActive'],
                    'updated_by' => Auth::id(),
                ]);

                return;
            }

            JobTitle::create([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'department_id' => $validated['departmentId'],
                'is_active' => (bool) $validated['isActive'],
                'organization_id' => $this->organizationId,
                'created_by' => Auth::id(),
            ]);
        });

        $this->closeJobTitleModal();

        LivewireAlert::title('Success!')->text($validated['name'] . ' saved successfully.')->success()->toast()->position('top-end')->show();
    }

    public function toggleJobTitleStatus(?int $id = null): void
    {
        $targetId = $id ?? $this->editingJobTitleId;

        if (! $targetId) {
            return;
        }

        $jobTitle = $this->jobTitlesQuery()->whereKey($targetId)->firstOrFail();

        $jobTitle->update([
            'is_active' => ! ((bool) $jobTitle->is_active),
        ]);

        if ($this->editingJobTitleId === $jobTitle->id) {
            $this->jobTitleActive = (bool) $jobTitle->fresh()->is_active;
        }

        LivewireAlert::title('Success!')->text($jobTitle->name . ' updated successfully.')->success()->toast()->position('top-end')->show();
    }

    public function deleteJobTitle(int $id): void
    {
        $this->toggleJobTitleStatus($id);
    }

    public function resetForm(): void
    {
        $this->editingJobTitleId = null;
        $this->name = '';
        $this->description = '';
        $this->departmentId = null;
        $this->isActive = 1;
    }

    #[On('discard-user-modal')]
    public function discardJobTitleModal(): void
    {
        $this->closeJobTitleModal();
    }

    public function getJobTitlesCountProperty(): int
    {
        return count($this->jobTitlesHierarchy);
    }
};
?>


<div class="sj-users-shell">

        <div class="toolbar">
            <div class="toolbar-left">
                <h2>Job Titles</h2>
                <span class="count-pill">{{ $this->jobTitlesCount }}</span>
            </div>

            <div class="toolbar-right">
                <div class="search">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path></svg>
                    <input
                        type="text"
                        wire:model.live="search"
                        placeholder="Search by job title"
                        aria-label="Search job titles"
                    >
                </div>

                <button class="btn btn-primary" type="button" wire:click="openAddJobTitleModal">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
                        Add Job Title
                </button>
            </div>
        </div>

        <table class="p-0">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Reports To</th>
                    <th>Approver Level</th>
                    <th>Holders</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->jobTitlesHierarchy as $jobTitle)
                    @php
                        if (!isset($jobTitle['name'])) {
                            continue;
                        }
                    @endphp
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="avatar">{{ strtoupper(substr($jobTitle['name'], 0, 2)) }}</div>
                                <div>
                                    <div class="u-name">{{ $jobTitle['name'] }}</div>
                                    <div class="u-email">{{ $jobTitle['department_name'] ?? 'N/A' }}</div>
                                    
                                </div>
                            </div>
                        </td>
                        @php
                            $reports_to_class = 'role-pill';
                            $reports_to = 'unassigned';
                            if (isset($jobTitle['reports_to']) && $jobTitle['reports_to'] != 'dangling') {
                                $reports_to_class = "su-name";
                                $reports_to = $jobTitle['reports_to'];
                            }

                        @endphp
                        <td><span class="{{ $reports_to_class }}" style="white-space: nowrap; display: inline-block;">{{ $reports_to }}</span></td>
                        @php
                            $level_class = 'role-pill';
                            if (isset($jobTitle['level']) && $jobTitle['level'] > 1) {
                                $level_class = "be-red text-primary";
                            } else if (isset($jobTitle['level']) && $jobTitle['level'] > 0) {
                                $level_class = 'small-red text-primary';
                            }

                        @endphp
                        <td style="white-space: nowrap;">
                            <span class="{{$level_class}}" style="white-space: nowrap; display: inline-block;">{{ 'Level-' . ($jobTitle['level'] ?? 'N/A') }}</span>
                        </td>
                        <td>
                            
                            <div>
                                <div class="u-name">{{ $jobTitle['employee_name'] }}</div>
                                <div class="u-email">{{ isset($jobTitle['direct_reporters_count']) ? $jobTitle['direct_reporters_count'] . ' '. $jobTitle['direct_reporters_title'] . ' report here' : 'N/A' }}</div>
                                
                            </div>
                        <td>
                            <span class="status {{ $jobTitle['is_active'] ? 'active' : 'inactive' }}">
                                {{ $jobTitle['is_active'] ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="action-cell">
                            <div class="dropdown">
                                <button class="kebab" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User actions">
                                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.5"></circle><circle cx="12" cy="12" r="1.5"></circle><circle cx="12" cy="19" r="1.5"></circle></svg>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end sj-action-menu">
                                    <li class="menu-item p-2">
                                        <span class="me-0">
                                            <i class="ti ti-pencil"></i>
                                        </span>
                                        <button type="button" class="menu-item m-0" wire:click="openEditJobTitleModal({{ $jobTitle['id'] }})">Edit job title</button>
                                    </li>
                                    <li class="menu-item p-2">
                                        @if ($jobTitle['is_active'])
                                            <span class="me-0">
                                                <i class="ti ti-lock"></i>
                                            </span>
                                        @else
                                            <span class="me-0">
                                                <i class="ti ti-lock-open"></i>
                                            </span>
                                        @endif
                                        <button type="button" class="menu-item" wire:click="toggleJobTitleStatus({{ $jobTitle['id'] }})">{{ $jobTitle['is_active'] ? 'Deactivate Job Title' : 'Reactivate job title' }}</button>
                                    </li>
                                    <li><hr class="menu-divider"></li>
                                    <li class="menu-item p-2">
                                        <span class="me-0">
                                            <i class="ti ti-trash-x-filled"></i>
                                        </span>
                                        <button type="button" class="menu-item danger" wire:click="deleteJobTitle({{ $jobTitle['id'] }})">Delete job title</button>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--ink-3);">No job titles match your search.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="footer-row">
            <span>
                Showing {{ count($this->jobTitlesHierarchy) }} job titles
            </span>
        </div>

    <div class="holding-modal p-0 m-0">
        <div class="modal p-0 m-0 fade {{ $showJobTitleModal ? 'show d-block' : '' }}" id="jobModal" tabindex="-1" role="dialog" aria-labelledby="user-modal-label" aria-hidden="{{ $showJobTitleModal ? 'false' : 'true' }}" style="{{ $showJobTitleModal ? 'display: block;' : 'display: none;' }}">
            <div class="modal-dialog modal-dialog-centered p-0 m-0" role="document" style="max-width: 900px;">
                <div class="modal-content user-modal-content shadow-sm border-0 m-0">
                    <div class="modal-header border-0 align-items-center px-4 pt-4 pb-2">
                        <div class="d-flex align-items-center gap-1 modal-title-wrap">
                            <div class="modal-user-icon">
                                <i class="ti ti-user-plus text-primary me-0"></i>
                                <!-- <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 8a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Zm-9 10a5.5 5.5 0 0 1 11 0v1h-11v-1Zm2 0v-.5A3.5 3.5 0 0 1 12 14.5a3.5 3.5 0 0 1 3.5 3.5v.5H8.5Zm12.5-9h-1.5v2h-2v1.5h2v2H21v-2h2V13h-2v-2h-1.5V10h1.5V8Z"/></svg> -->
                            </div>
                            <h5 id="user-modal-label" class=" mb-0 ms-0">{{ $editingJobTitleId ? 'Edit job title' : 'Add job title' }}</h5>
                        </div>
                        <button type="button" class="btn-close" aria-label="Close" wire:click="closeJobTitleModal"></button>
                    </div>

                    <div class="modal-body px-4 pb-4">
                        <p class="modal-subtitle">{{ $editingJobTitleId ? 'Update job title details.' : 'Create a new job title.' }}</p>
                        <form wire:submit.prevent="saveJobTitle" class="job-title-form">
                            <div class="field">
                                <label for="job-title-name" >Job Title</label>
                                <input id="job-title-name" type="text" wire:model.defer="name" placeholder="Software Engineer" />
                                @error('name')
                                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                                @enderror
                            </div>

                            <div class="field mt-1">
                                <label for="job-title-description" >Description</label>
                                <input id="job-title-description" type="text" wire:model.defer="description" placeholder="Responsible for developing software applications." />
                            </div>

                            <label class="form-label">Department</label>
                            <select wire:model.defer="departmentId" class="form-control">
                                <option value="">Select a department</option>
                                @foreach ($this->departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                            @error('departmentId')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror

                            <label class="form-label">Status</label>
                            <select wire:model.defer="isActive" class="form-control">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>

                            <div class="modal-foot">
                                <button type="button" class="btn" wire:click="closeJobTitleModal">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    {{ $editingJobTitleId ? 'Save changes' : 'Add job title' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('styles')
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        .sj-users-shell {
            --sj-red: #c81e2c;
            --sj-red-dark: #9e1622;
            --sj-red-tint: #fbe9ea;
            --ink: #1b1d22;
            --ink-2: #565b66;
            --ink-3: #8b9099;
            --line: #e7e8ec;
            --line-strong: #d7d9e0;
            --surface: #ffffff;
            --page: #f5f6f8;
            --panel: #fafafb;
            --green: #1c8a4e;
            --green-tint: #e8f6ee;
            --gray-tint: #efeff1;
            --radius: 10px;
            --shadow: 0 1px 2px rgba(20, 20, 30, 0.04), 0 8px 24px rgba(20, 20, 30, 0.05);
            font-family: 'Inter', -apple-system, sans-serif;
            /* background: var(--page); */
            color: var(--ink);
            /* border: 1px solid var(--line);
            border-radius: 12px;*/
            padding: 0; 
        }


        .sj-users-shell .icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            vertical-align: -3px;
        }

        .sj-users-shell .pageheader {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            /* padding: 20px 24px; */
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .sj-users-shell .pageheader h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.01em;
        }

        .sj-users-shell .crumbs {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            color: var(--ink-3);
        }

        .sj-users-shell .sep {
            color: var(--ink-3);
            font-size: 13px;
        }

        .sj-users-shell .crumb-pill {
            background: var(--sj-red-tint);
            color: var(--sj-red);
            border-radius: 100px;
            padding: 7px 14px;
            font-weight: 600;
        }

        .sj-users-shell .sectionnav {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 0 14px;
        }

        .sj-users-shell .section-tab {
            padding: 17px 16px;
            font-size: 14.5px;
            font-weight: 600;
            color: var(--ink-2);
            border-bottom: 2.5px solid transparent;
        }

        .sj-users-shell .section-tab.active {
            color: var(--sj-red);
            border-bottom-color: var(--sj-red);
        }

        .sj-users-shell .tabnav {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-top: none;
            padding: 0 14px;
        }

        .sj-users-shell .tab {
            padding: 14px 12px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--ink-2);
            border-bottom: 2px solid transparent;
        }

        .sj-users-shell .tab.active {
            color: var(--sj-red);
            border-bottom-color: var(--sj-red);
            font-weight: 600;
        }

        .sj-users-shell .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
        }

        .sj-users-shell .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }

        .sj-users-shell .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sj-users-shell .toolbar h2 {
            font-size: 17px;
            font-weight: 600;
            margin: 0;
        }

        .sj-users-shell .count-pill {
            background: var(--gray-tint);
            color: var(--ink-2);
            font-size: 12px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 100px;
        }

        .sj-users-shell .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            justify-content: flex-end;
        }

        .sj-users-shell .search {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            padding: 8px 12px;
            min-width: 220px;
            background: var(--panel);
        }

        .sj-users-shell .search input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 13px;
            width: 100%;
            color: var(--ink);
            font-family: inherit;
        }

        .sj-users-shell .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            padding: 9px 14px;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink);
            transition: background 0.12s ease, border-color 0.12s ease;
        }

        .sj-users-shell .btn:hover {
            background: var(--panel);
        }

        .sj-users-shell .btn-primary {
            background: var(--sj-red);
            border-color: var(--sj-red);
            color: #fff;
        }

        .sj-users-shell .btn-primary:hover {
            background: var(--sj-red-dark);
            border-color: var(--sj-red-dark);
            color: #fff;
        }

        .sj-users-shell .sj-filter-menu,
        .sj-users-shell .sj-action-menu {
            border: 1px solid var(--line);
            border-radius: 9px;
            box-shadow: var(--shadow);
            padding: 5px;
            min-width: 190px;
        }

        .sj-users-shell table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .sj-users-shell thead th {
            text-align: left;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: capitalize;
            color: var(--ink-3);
            padding: 12px 22px;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
        }

        .sj-users-shell tbody td {
            padding: 14px 22px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            color: var(--ink);
        }

        .sj-users-shell tbody tr:hover {
            background: #fcfcfd;
        }

        .sj-users-shell .user-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .sj-users-shell .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11.5px;
            font-weight: 700;
            background: var(--sj-red-tint);
            color: var(--sj-red-dark);
            flex-shrink: 0;
        }

        .sj-users-shell .u-name {
            font-weight: 600;
            font-size: 13.5px;
        }

        .sj-users-shell .su-name {
            font-weight: 500;
            font-size: 11.5px;
        }

        .sj-users-shell .u-email {
            color: var(--ink-2);
            font-size: 12.5px;
            margin-top: 1px;
        }

        .sj-users-shell .role-pill {

            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;

            background: var(--gray-tint);
            color: var(--ink-2);
        }  

        .sj-users-shell .be-red {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;

            background: #FCE8CF !important;
        }

        .sj-users-shell .small-red {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;

            background: #FDECEA !important;
        }

        .sj-users-shell .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;
        }

        .sj-users-shell .status::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .sj-users-shell .status.active {
            background: var(--green-tint);
            color: var(--green);
        }

        .sj-users-shell .status.active::before {
            background: var(--green);
        }

        .sj-users-shell .status.inactive {
            background: var(--gray-tint);
            color: var(--ink-2);
        }

        .sj-users-shell .status.inactive::before {
            background: var(--ink-3);
        }

        .sj-users-shell .last-login {
            color: var(--ink-3);
            font-size: 12.5px;
            white-space: nowrap;
        }

        .sj-users-shell .action-cell {
            text-align: right;
        }

        .sj-users-shell .kebab {
            width: 32px;
            height: 32px;
            border-radius: 7px;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink-2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .sj-users-shell .kebab:hover {
            background: var(--panel);
            color: var(--ink);
            border-color: var(--ink-3);
        }

        .sj-users-shell .menu-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 0;
            font-size: 13px;
            border-radius: 6px;
            color: var(--ink);
            border: none;
            background: transparent;
            text-align: left;
        }

        .sj-users-shell .menu-item:hover {
            background: var(--panel);
        }

        .sj-users-shell .menu-item.danger {
            color: var(--sj-red);
        }

        .sj-users-shell .menu-divider {
            height: 1px;
            background: var(--line);
            margin: 5px 2px;
            border: 0;
        }

        .sj-users-shell .footer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 22px;
            font-size: 12.5px;
            color: var(--ink-3);
        }

        .sj-users-shell .page-btns {
            display: flex;
            gap: 6px;
        }

        .sj-users-shell .page-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            font-size: 12.5px;
            color: var(--ink-2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sj-users-shell .page-btn.current {
            background: var(--ink);
            border-color: var(--ink);
            color: #fff;
            font-weight: 600;
        }

        .sj-users-shell .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 28, 0.44);
            align-items: center;
            justify-content: center;
            z-index: 100;
            padding: 20px;
        }

        .sj-users-shell .overlay.open {
            display: flex;
        }

        .sj-users-shell .holding-modal .modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);

            width: 100%;
            max-width: 500px;
            height: auto;
            max-height: 90vh;
            min-height: 0;

            margin: 0;
            padding: 0;

            background: transparent;
            border: none;
            box-shadow: none;

            overflow: visible;
        }

        .sj-users-shell .holding-modal .modal-dialog {
            width: 100%;
            max-width: 500px;
            height: auto;
            min-height: 0;

            margin: 0;
        }

        .sj-users-shell .holding-modal .modal-content {
            width: 100%;
            height: auto;
            min-height: 0;

            margin: 0;
            padding: 0;

            border: none;
            border-radius: 14px;
        }

        .sj-users-shell  .holding-modal .modal::-webkit-scrollbar {
            display: none;
        }

        .sj-users-shell .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px 0;
        }

        .sj-users-shell .title-row {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .sj-users-shell .title-row .icon {
            width: 19px;
            height: 19px;
            color: var(--sj-red);
        }

        .sj-users-shell .modal-head h2 {
            font-size: 1px;
            font-weight: 600;
            margin: 0;
        }

        .sj-users-shell .modal-close {
            width: 28px;
            height: 28px;
            border: none;
            background: transparent;
            border-radius: 7px;
            color: var(--ink-3);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sj-users-shell .modal-close:hover {
            background: var(--panel);
            color: var(--ink);
        }

        .sj-users-shell .modal-sub {
            font-size: 10px;
            color: var(--ink-2);
            padding: 6px 22px 18px;
            margin: 0;
        }

        .sj-users-shell .modal-body {
            padding: 0 22px 6px;
            display: flex;
            flex-direction: column;
            /* gap: 14px; */
        }

        .sj-users-shell .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink-2);
            margin-bottom: 6px;
        }

        .sj-users-shell .field input,
        .sj-users-shell .field select {
            width: 100%;
            padding: 9px 11px;
            border-radius: 8px;
            border: 1px solid var(--line-strong);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--ink);
            background: var(--surface);
            outline: none;
        }

        .sj-users-shell .field input:focus,
        .sj-users-shell .field select:focus {
            border-color: var(--sj-red);
        }

        .sj-users-shell .form-error {
            font-size: 12.5px;
            color: var(--sj-red);
            margin: 6px 0 0;
        }

        .sj-users-shell .account-actions {
            display: none;
            flex-direction: column;
            gap: 10px;
            margin-top: 2px;
        }

        .sj-users-shell .account-actions.show {
            display: flex;
        }

        .sj-users-shell .account-action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 12px 14px;
        }

        .sj-users-shell .aa-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .sj-users-shell .aa-sub {
            font-size: 12px;
            color: var(--ink-2);
            margin-top: 2px;
        }

        .sj-users-shell .account-action-row .btn {
            white-space: nowrap;
            padding: 8px 12px;
            font-size: 12.5px;
        }

        .sj-users-shell .is-deactivate {
            color: var(--sj-red);
            border-color: var(--sj-red-tint);
        }

        .sj-users-shell .is-deactivate:hover {
            background: var(--sj-red-tint);
        }

        .sj-users-shell .is-reactivate {
            color: var(--green);
            border-color: var(--green-tint);
        }

        .sj-users-shell .is-reactivate:hover {
            background: var(--green-tint);
        }

        .sj-users-shell .modal-foot {
            display: flex;
            justify-content: flex-end;
            /* gap: 10px; */
            /* padding: 20px 22px 22px; */
        }

        @media (max-width: 640px) {
            .sj-users-shell .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .sj-users-shell .toolbar-right {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }

            .sj-users-shell .search {
                width: 100%;
            }

            .sj-users-shell .footer-row {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .sj-users-shell thead {
                display: none;
            }

            .sj-users-shell table,
            .sj-users-shell tbody,
            .sj-users-shell tr,
            .sj-users-shell td {
                display: block;
                width: 100%;
            }

            .sj-users-shell tbody tr {
                border-bottom: 1px solid var(--line);
                padding: 12px 4px;
            }

            .sj-users-shell tbody td {
                border-bottom: none;
                padding: 6px 18px;
            }

            .sj-users-shell .action-cell {
                text-align: left;
            }

            .sj-users-shell .pageheader {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .sj-users-shell .sectionnav {
                overflow-x: auto;
            }
        }
    </style>
@endpush


@push('scripts')
    <script>
        window.addEventListener('show-user-modal', () => {
            const modal = document.getElementById('userModal');
            if (!modal) return;
            const bootstrapModal = new bootstrap.Modal(modal, {
                backdrop: 'static',
                keyboard: false,
            });
            bootstrapModal.show();
        });

        window.addEventListener('hide-user-modal', () => {
            const modal = document.getElementById('userModal');
            if (!modal) return;
            const instance = bootstrap.Modal.getInstance(modal);
            instance ? instance.hide() : null;
        });
    </script>
@endpush

