<?php

use Livewire\Volt\Component;
use App\Models\Organization;

new class extends Component {

    public ?string $logoPath    = null;
    public string  $orgName     = '';
    public string  $primaryColor = '#072639';
    public int     $logoHeight  = 60;
    public int     $logoWidth   = 200;

    public function mount()
    {
        $org = Organization::find(auth()->user()->employee->organization_id);

        if ($org) {
            $this->logoPath     = $org->logo_path;
            $this->orgName      = $org->name;
            $this->primaryColor = $org->primary_color  ?? '#072639';
            $this->logoHeight   = $org->logo_height    ?? 60;
            $this->logoWidth    = $org->logo_width      ?? 200;
        }
    }

};?>

<div>
    @if ($logoPath)
        <img
            src="{{ asset('storage/' . $logoPath) }}"
            alt="{{ $orgName }} logo"
            style="height: {{ $logoHeight }}px; width: {{ $logoWidth }}px; margin-left: -10px; object-fit: contain;"
        >
    @else
        <div style="
            width: {{ $logoWidth }}px;
            height: {{ $logoHeight }}px;
            background-color: {{ $primaryColor }};
            color: #fff;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: -10px;
        ">
            {{ strtoupper(substr($orgName, 0, 2)) }}
        </div>
    @endif
</div>
