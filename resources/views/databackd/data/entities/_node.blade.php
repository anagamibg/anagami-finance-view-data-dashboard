@php /** @var array $node */ $e = $node['entity']; @endphp
<li class="dbk-scheme-node">
    <span class="dbk-scheme-box @if (!$e->is_active) dbk-scheme-inactive @endif">
        <i class="{{ $node['children'] ? 'fa' : 'far' }} fa-building dbk-scheme-icon" aria-hidden="true"></i>
        <span class="dbk-scheme-name">{{ $e->name }}</span>
        <span class="dbk-scheme-meta">{{ $e->country }} · {{ $e->currency }}</span>
        @if ($node['effective'] !== null)
            <span class="dbk-badge is-info no-dot">{{ rtrim(rtrim(number_format($e->ownership_pct, 2, ',', ''), '0'), ',') }}%</span>
            @if (abs($node['effective'] - $e->ownership_pct) > 0.001)
                <span class="dbk-scheme-meta">({{ rtrim(rtrim(number_format($node['effective'], 2, ',', ''), '0'), ',') }}%)</span>
            @endif
        @endif
    </span>
    @if ($node['children'])
        <ul>
            @foreach ($node['children'] as $child)
                @include('databackd.data.entities._node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
