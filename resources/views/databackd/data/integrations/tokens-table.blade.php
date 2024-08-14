@if ($tokens->isEmpty())
    <x-dbk.empty icon="key" :text="__('databackd-data-integrations.tokens.empty')" size="sm" />
@else
    <div class="table-responsive">
        <table class="dbk-table dbk-table--compact" id="tokens-table">
            <thead>
            <tr class="text-dark-grey">
                <th>@lang('databackd-data-integrations.tokens.name')</th>
                <th>@lang('databackd-data-integrations.tokens.token')</th>
                <th>@lang('databackd-data-integrations.tokens.created_at')</th>
                <th>@lang('databackd-data-integrations.tokens.last_used')</th>
                <th>@lang('databackd-data-integrations.tokens.status')</th>
                <th class="text-right"></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($tokens as $token)
                <tr @class(['text-lightest' => !$token->is_active])>
                    <td class="f-w-500">{{ $token->name }}</td>
                    <td><code class="text-dark-grey">dbk_&hellip;{{ $token->last_four }}</code></td>
                    <td>{{ $token->created_at ? $token->created_at->timezone(company()->timezone)->translatedFormat(company()->date_format . ' ' . company()->time_format) : '--' }}</td>
                    <td>
                        @if ($token->last_used_at)
                            {{ $token->last_used_at->timezone(company()->timezone)->translatedFormat(company()->date_format . ' ' . company()->time_format) }}
                        @else
                            <span class="text-lightest">@lang('databackd-data-integrations.tokens.never_used')</span>
                        @endif
                    </td>
                    <td>
                        @if ($token->is_active)
                            <span class="dbk-badge is-good">@lang('databackd-data-integrations.tokens.active')</span>
                        @else
                            <span class="dbk-badge">@lang('databackd-data-integrations.tokens.revoked')</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($canManage && $token->is_active)
                            <button type="button" class="btn btn-sm btn-outline-danger rounded f-12 revoke-token"
                                    data-id="{{ $token->id }}" data-url="{{ route('databackd.data.tokens.destroy', $token->id) }}">
                                <i class="fa fa-ban mr-1"></i>@lang('databackd-data-integrations.tokens.revoke')
                            </button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
