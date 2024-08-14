@if (databackd_module('databackd_integrations')) {{-- AFV (S8): package module --}}
<x-sub-menu-item :link="route('databackd.data.integrations.index')" :text="__('databackd-data-integrations.menu')"
                 data-dbk-match="account/databackd/data/sync" /> {{-- AFV ui (S12): the invoices/expenses sync is opened from Integrations --}}
@endif
