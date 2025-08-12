@push('styles')
    <style>
        /* Profile Header */
        .profile-header {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }
        .profile-photo {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            border: 3px solid #dee2e6;
            object-fit: cover;
            background: white;
        }
        .profile-info h3 {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: #2c3e50;
        }
        .profile-info p {
            margin: 0;
            color: #6c757d;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
        }
        .nav-tabs .nav-link {
            color: #495057;
            border: none;
            padding: 0.75rem 1.25rem;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 600;
            border-radius :0px;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }

        /* Cards */
        .stat-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            background: white;
        }

        /* Table styling */
        .table thead {
            background-color: #f8f9fa;
        }
        .table tbody tr:hover {
            background-color: #f1f3f5;
        }

        /* Badges */
        .badge-late {
            background-color: #ffc107;
            color: #212529;
        }
        .badge-onleave {
            background-color: #0dcaf0;
            color: #212529;
        }

        .summary-info {
            margin-bottom: 1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            justify-content: space-between; /* distribute space */
            align-items: center; /* vertical center */
            gap: 1.5rem;
        }
        .summary-info .summary-left {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }


        .profile-header {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }
        .profile-initials {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background-color: #0d6efd; /* bootstrap primary */
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 48px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            user-select: none;
            box-shadow: 0 0 10px rgba(13,110,253,0.4);
        }
        .profile-info h3 {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: #2c3e50;
        }
        .profile-info p {
            margin: 0;
            color: #6c757d;
        }

    </style>
@endpush

<div class="container py-4">

    <!-- Profile Header -->
    <div class="profile-header mb-4">
        <div class="profile-initials">
            JD
        </div>
        <div class="profile-info">
            <h3>John Doe</h3>
            <p>Systems Analyst - IT Support</p>
            <small>Hired: Jan 10, 2022 | Employee ID: EMP001</small>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#attendance">Attendance</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#overtime">Overtime</a></li>
    </ul>

    <div class="tab-content">

        <!-- Overview Tab -->
        <div class="tab-pane fade show active" id="overview">
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <h6 class="text-muted">Monthly Attendance</h6>
                        <h3 class="text-primary">92%</h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <h6 class="text-muted">Overtime Hours</h6>
                        <h3 class="text-warning">12.5</h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <h6 class="text-muted">Days Present</h6>
                        <h3 class="text-success">21</h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <h6 class="text-muted">Days Absent</h6>
                        <h3 class="text-danger">2</h3>
                    </div>
                </div>
            </div>

            <!-- Start Basic Line Chart -->
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">Basic Line Chart</h4>
                    <div id="chart-line-basic"></div>
                </div>
            </div>
        </div>

        <!-- Attendance Tab -->
        <div class="tab-pane fade" id="attendance">
            <div class="summary-info">
                <div class="summary-left">
                    <div>Days Present: <span class="text-success">21</span></div>
                    <div>Days Absent: <span class="text-danger">2</span></div>
                    <div>Late Days: <span class="badge badge-late">3</span></div>
                    <div>On Leave: <span class="badge badge-onleave">1</span></div>
                </div>
                <button class="btn p-2 btn-primary btn-sm d-flex align-items-center" id="exportAttendanceBtn" type="button">
                    <iconify-icon icon="mdi:download-outline" class="fs-5 me-2 text-white"></iconify-icon>
                    Download Attendance Reports
                </button>
            </div>
            <h6 class="mb-3">Weekly Attendance Summary</h6>
            <table class="table table-bordered text-center bg-white">
                <thead>
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
                    <td><span class="badge bg-warning text-dark">Late</span></td>
                </tr>
                <tr>
                    <td>2025-08-03</td>
                    <td>-</td>
                    <td>-</td>
                    <td>0.00</td>
                    <td><span class="badge bg-danger">Absent</span></td>
                </tr>
                <tr>
                    <td>2025-08-02</td>
                    <td>08:00</td>
                    <td>17:00</td>
                    <td>9.00</td>
                    <td><span class="badge bg-info text-dark">On Leave</span></td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- Overtime Tab -->
        <div class="tab-pane fade" id="overtime">
            <div class="summary-info mb-3" style="justify-content: space-between; align-items: center; display: flex;">
                <div>Total Overtime Hours: <span class="text-warning fw-bold">12.5</span></div>
                <button class="btn btn-primary p-2 btn-sm d-flex align-items-center" id="exportOvertimeBtn" type="button">
                    <iconify-icon icon="mdi:download-outline" class="fs-5 me-2 text-white"></iconify-icon>
                    Download Overtime Reports
                </button>
            </div>
            <h6 class="mb-3">Overtime Summary</h6>
            <table class="table table-bordered text-center bg-white">
                <thead>
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
                <tr>
                    <td>2025-07-25</td>
                    <td>19:00</td>
                    <td>21:00</td>
                    <td>2.0</td>
                    <td>Emergency Fix</td>
                    <td>Mark Supervisor</td>
                </tr>
                </tbody>
            </table>
        </div>

    </div>
</div>

@push('scripts')
    <script src="../assets/js/apex-chart/apex.line.init.js"></script>
@endpush
