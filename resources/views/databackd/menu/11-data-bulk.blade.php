@if (databackd_module('databackd_data')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.data.bulk.index')" :text="__('databackd-data-bulk.menu')" />
@endif
