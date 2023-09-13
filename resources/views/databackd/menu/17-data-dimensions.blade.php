@if (databackd_module('databackd_data')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.data.dimensions.index')" :text="__('databackd-data-dimensions.menu')" />
<x-sub-menu-item :link="route('databackd.data.entities.index')" :text="__('databackd-data-dimensions.menu_entities')" />
@endif
