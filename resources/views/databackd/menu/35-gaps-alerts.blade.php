@if (databackd_module('databackd_analytics')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.gaps.alerts.index')" :text="__('databackd-gaps-alerts.menu')" />
@endif
