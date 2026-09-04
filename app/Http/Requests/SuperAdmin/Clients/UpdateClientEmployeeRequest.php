<?php

namespace App\Http\Requests\SuperAdmin\Clients;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientEmployeeRequest extends FormRequest
{
    public function rules(): array
    {
        $organization = $this->route('organization');
        $employeeId = $this->route('employeeId');
        $userId = Employee::find($employeeId)?->user_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                'unique:employees,email,' . $employeeId,
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['required', 'string', 'max:20'],
            'id_number' => ['nullable', 'string', 'unique:employees,id_number,' . $employeeId],
            'active' => ['nullable', 'boolean'],
            'employee_title' => ['nullable', 'string', 'max:255'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where(
                fn ($query) => $query->where('organization_id', $organization->id)
            )],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')->where(
                fn ($query) => $query->where('organization_id', $organization->id)
            )],
            'job_title_id' => ['nullable', 'integer', Rule::exists('job_titles', 'id')->where(
                fn ($query) => $query->where('organization_id', $organization->id)
            )],
            'reports_to_job_title_id' => ['nullable', 'integer', Rule::exists('job_titles', 'id')->where(
                fn ($query) => $query->where('organization_id', $organization->id)
            )],
            'reports_to_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where(
                fn ($query) => $query->where('organization_id', $organization->id)
            )],
            'is_user' => ['nullable', 'boolean'],
            'role_name' => ['nullable', 'string', 'required_if:is_user,1'],
        ];
    }
}
