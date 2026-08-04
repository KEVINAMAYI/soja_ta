{{--
    Flat, searchable, multi-select department filter — one option per derived
    (canonical) department group. Pick none for "All Departments", or pick
    several groups to filter/export just those departments together.

    Props:
      $groupedDepartments  Collection keyed by department_derived, each value a Collection of
                            ['id' => ..., 'name' => ...] arrays (e.g. from Department::groupedByDerived($orgId)).
      $selectId             Unique element id for this instance (a page can render more than one).
      $dispatchEvent         Livewire event name to fire on change, e.g. "filter-updated".
      $selectedDepartmentIds Array of currently-selected department ids (empty array = "All Departments").

    Value encoding (parsed by initDeptGroupSelects() in the app layout):
      each selected <option> value is "1,2,3" — every department id under one derived
      group; the ids from all selected options are flattened into one department_ids array.
--}}
@php
    $selectedSet = collect($selectedDepartmentIds ?? [])->map(fn ($id) => (string) $id)->values();
@endphp

<div wire:ignore>
    <select id="{{ $selectId }}"
            class="form-control dept-group-select"
            data-dispatch-event="{{ $dispatchEvent }}"
            multiple>
        @foreach($groupedDepartments as $groupName => $members)
            @php
                $naturalIds = $members->pluck('id')->map(fn ($id) => (string) $id)->values();
                $groupIds = $naturalIds->implode(',');
                $isSelected = $naturalIds->isNotEmpty() && $naturalIds->diff($selectedSet)->isEmpty();
            @endphp
            <option value="{{ $groupIds }}" @selected($isSelected)>{{ $groupName }}</option>
        @endforeach
    </select>
</div>
