{{-- AFV (B4): tabs of the alerts screens. Needs $alertTab (rules|history) and $openCount (alerts that are not confirmed yet) --}}
<ul class="nav nav-tabs mb-3 al-tabs">
    <li class="nav-item">
        <a class="nav-link f-14 {{ $alertTab === 'rules' ? 'active' : '' }}" href="{{ route('databackd.gaps.alerts.index') }}">
            <i class="fa fa-sliders-h mr-1"></i>@lang('databackd-gaps-alerts.tabs.rules')
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link f-14 {{ $alertTab === 'history' ? 'active' : '' }}" href="{{ route('databackd.gaps.alerts.history') }}">
            <i class="fa fa-history mr-1"></i>@lang('databackd-gaps-alerts.tabs.history')
            @if ($openCount > 0)
                <span class="badge badge-danger ml-1">{{ $openCount }}</span>
            @endif
        </a>
    </li>
</ul>
