<div class="space-y-1 leading-tight text-sm text-gray-800">
    <div class="font-semibold text-base text-black">{{ $overtime->employee->name }}</div>

    <div class="text-xs flex items-center gap-1">
        <a href="mailto:{{ $overtime->employee->email }}" class="text-blue-600 hover:underline">
            {{ $overtime->employee->email }}
        </a>
    </div>

    <div class="text-xs flex items-center gap-1">
        <iconify-icon style="color:green;" icon="mdi:phone-outline" class="w-4 h-4"></iconify-icon>
        <span class="text-gray-700">{{ $overtime->employee->phone ?? 'N/A' }}</span>
    </div>
</div>
