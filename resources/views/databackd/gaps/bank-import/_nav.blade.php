{{-- Tabs of the bank import screens. Variable: $active = import|statements|rules. Styles: resources/scss/databackd/_bank-fx.scss --}}
<nav aria-label="{{ __('databackd-gaps-bank-import.menu') }}">
<ul class="nav nav-pills bank-import-nav mb-3">
    <li class="nav-item">
        <a class="nav-link @if ($active === 'import') active @endif" @if ($active === 'import') aria-current="page" @endif href="{{ route('databackd.gaps.bank-import.index') }}">
            <i class="fa fa-upload mr-1"></i>@lang('databackd-gaps-bank-import.nav.import')
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link @if ($active === 'statements') active @endif" @if ($active === 'statements') aria-current="page" @endif href="{{ route('databackd.gaps.bank-import.statements') }}">
            <i class="fa fa-university mr-1"></i>@lang('databackd-gaps-bank-import.nav.statements')
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link @if ($active === 'rules') active @endif" @if ($active === 'rules') aria-current="page" @endif href="{{ route('databackd.gaps.bank-import.rules.index') }}">
            <i class="fa fa-random mr-1"></i>@lang('databackd-gaps-bank-import.nav.rules')
        </a>
    </li>
</ul>
</nav>
