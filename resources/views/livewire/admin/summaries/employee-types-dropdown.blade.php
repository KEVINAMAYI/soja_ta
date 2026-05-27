<?php

use App\Models\Role;
use Livewire\Volt\Component;

new class extends Component {

    public $roles = [];

    public function mount()
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $this->roles = Role::where('organization_id', $orgId)
            ->where('name', '!=', 'super-admin')
            ->get();
    }

}; ?>


<li class="sidebar-item">
    <a class="sidebar-link {{ request()->routeIs('employees.roles.*') ? 'active' : '' }}"
       href="#employeesMenu"
       data-bs-toggle="collapse"
       aria-expanded="{{ request()->routeIs('employees.roles.*') ? 'true' : 'false' }}"
       aria-controls="employeesMenu">
        <iconify-icon icon="mdi:account-group-outline"></iconify-icon>
        <span class="hide-menu">Employees</span>
    </a>

    <ul class="collapse first-level {{ request()->routeIs('employees.roles.*') ? 'show' : '' }}"
        id="employeesMenu">
        @foreach($roles as $role)
            <li class="sidebar-item">
                <a class="sidebar-link {{ request()->routeIs('employees.roles.index') && request()->route('role')?->id == $role->id ? 'active' : '' }}"
                   href="{{ route('employees.roles.index', $role->id) }}">
                    <span class="icon-small"></span>
                    {{ ucfirst($role->name) }}
                </a>
            </li>
        @endforeach
    </ul>
</li>









