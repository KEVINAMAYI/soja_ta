<?php

use Livewire\Volt\Component;

new class extends Component {
}; ?>

@push('styles')
    <style>
        .card-metric {
            transition: all 0.3s;
        }

        .card-metric:hover {
            transform: scale(1.02);
            box-shadow: 0 0 0.7rem rgba(0, 0, 0, 0.1);
        }
    </style>
@endpush


<div style="background-color:white; border-radius : 15px;" class="container p-5">

    <!-- Header & Export -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">John Doe</h2>
            <p class="text-muted">Employee ID: EMP001</p>
        </div>
        <div>
            <button class="btn btn-outline-primary">
                <iconify-icon icon="mdi:export-variant" class="me-1"></iconify-icon>
                Export Reports
            </button>
        </div>
    </div>

    <!-- Profile Info -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="p-3 bg-white rounded shadow-sm">
                <strong>Department:</strong> IT Support
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="p-3 bg-white rounded shadow-sm">
                <strong>Position:</strong> Systems Analyst
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="p-3 bg-white rounded shadow-sm">
                <strong>Date Hired:</strong> Jan 10, 2022
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row text-center mb-4">
        <div class="col-md-3 mb-3">
            <div class="p-4 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">This Week's Hours</h6>
                <h2 class="text-primary fw-bold">38.5</h2>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="p-4 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">Monthly Attendance</h6>
                <h2 class="text-success fw-bold">92%</h2>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="p-4 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">Total Overtime</h6>
                <h2 class="text-warning fw-bold">12.5</h2>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="p-4 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">Status</h6>
                <h2 class="text-success fw-bold">Present</h2>
            </div>
        </div>
    </div>

    <!-- Additional Stats -->
    <div class="row mb-4 text-center">
        <div class="col-md-4 mb-3">
            <div class="p-3 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">Days Present</h6>
                <h4 class="text-success">21</h4>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="p-3 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">Days Absent</h6>
                <h4 class="text-danger">2</h4>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="p-3 bg-white rounded shadow-sm card-metric">
                <h6 class="text-muted">On Leave</h6>
                <h4 class="text-warning">1</h4>
            </div>
        </div>
    </div>

    <!-- Weekly Attendance Table -->
    <h5 class="mb-3">Weekly Attendance Summary</h5>
    <div class="table-responsive mb-4">
        <table class="table table-bordered text-center bg-white">
            <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Check-In</th>
                <th>Check-Out</th>
                <th>Worked Hours</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>2025-08-05</td>
                <td>08:00</td>
                <td>17:00</td>
                <td>9.00</td>
                <td><span class="badge bg-success">Present</span></td>
            </tr>
            <tr>
                <td>2025-08-04</td>
                <td>08:10</td>
                <td>16:50</td>
                <td>8.67</td>
                <td><span class="badge bg-success">Present</span></td>
            </tr>
            <tr>
                <td>2025-08-03</td>
                <td>-</td>
                <td>-</td>
                <td>0.00</td>
                <td><span class="badge bg-danger">Absent</span></td>
            </tr>
            </tbody>
        </table>
    </div>

    <!-- Overtime Summary Table -->
    <h5 class="mb-3">Overtime Summary</h5>
    <div class="table-responsive">
        <table class="table table-bordered text-center bg-white">
            <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Hours</th>
                <th>Reason</th>
                <th>Approved By</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>2025-08-01</td>
                <td>18:00</td>
                <td>20:30</td>
                <td>2.5</td>
                <td>System Upgrade</td>
                <td>Jane Admin</td>
            </tr>
            <tr>
                <td>2025-07-29</td>
                <td>17:30</td>
                <td>19:00</td>
                <td>1.5</td>
                <td>Late reporting</td>
                <td>-</td>
            </tr>
            </tbody>
        </table>
    </div>

</div>







