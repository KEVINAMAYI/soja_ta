@props(['url'])
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            {{-- Always use a full URL. config('app.url') pulls from your .env --}}
            <img src="{{ config('app.url') }}/images/logos/soja_ta_logo.png" class="logo" alt="{{ config('app.name') }}" style="width: 200px;">
        </a>
    </td>
</tr>
