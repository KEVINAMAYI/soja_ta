{{--
    Flat, searchable department filter — one option per derived (canonical) department group.

    Props:
      $groupedDepartments  Collection keyed by department_derived, each value a Collection of
                            ['id' => ..., 'name' => ...] arrays (e.g. from Department::groupedByDerived($orgId)).
      $selectId             Unique element id for this instance (a page can render more than one).
      $dispatchEvent         Livewire event name to fire on change, e.g. "filter-updated".
      $selectedDepartmentIds Array of currently-selected department ids (empty array = "All Departments").

    Value encoding (parsed by initDeptGroupSelects() in the app layout):
      "all"    -> no department filter
      "1,2,3"  -> every department id under one derived group
--}}
@php
    // Order-independent (sorted-set) comparison against each group's natural id order,
    // so the rendered option's value="" (also natural order) is what actually gets selected.
    $selectedSet = collect($selectedDepartmentIds ?? [])->map(fn ($id) => (string) $id)->sort()->values();

    $selectedValue = 'all';
    if ($selectedSet->isNotEmpty()) {
        foreach ($groupedDepartments as $members) {
            $naturalIds = $members->pluck('id')->map(fn ($id) => (string) $id)->values();
            if ($naturalIds->sort()->values()->all() === $selectedSet->all()) {
                $selectedValue = $naturalIds->implode(',');
                break;
            }
        }
    }
@endphp

<div wire:ignore>
    <select id="{{ $selectId }}"
            class="form-control dept-group-select"
            data-dispatch-event="{{ $dispatchEvent }}">
        <option value="all" @selected($selectedValue === 'all')>All Departments</option>
        @foreach($groupedDepartments as $groupName => $members)
            @php $groupIds = $members->pluck('id')->implode(','); @endphp
            <option value="{{ $groupIds }}" @selected($selectedValue === $groupIds)>{{ $groupName }}</option>
        @endforeach
    </select>
</div>
