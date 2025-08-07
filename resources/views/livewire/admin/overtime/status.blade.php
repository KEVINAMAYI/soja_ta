@if ($row->status === 'approved')
    <span class="badge bg-success">Approved</span>
    @if($row->approver)
        <div class="small mt-1 text-dark-50">By {{ $row->approver->name }}</div>
    @endif
@elseif ($row->status === 'rejected')
    <span class="badge bg-danger">Rejected</span>
    @if($row->approver)
        <div class="small text-dark-50">By {{ $row->approver->name }}</div>
    @endif
@else
    <span class="badge bg-warning text-dark">Pending</span>
@endif

