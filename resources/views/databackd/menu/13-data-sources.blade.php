@if (databackd_module('databackd_integrations')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.data.sources.index')" :text="__('databackd-data-sources.menu')" />
@endif
