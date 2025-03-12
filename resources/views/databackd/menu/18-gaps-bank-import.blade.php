@if (databackd_module('databackd_data')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.gaps.bank-import.index')" :text="__('databackd-gaps-bank-import.menu')" />
@endif
