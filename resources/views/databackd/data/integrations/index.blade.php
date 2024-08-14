@extends('layouts.app')

@section('content')
    <div class="content-wrapper dbk-data-page dbk-data-integrations">

        <x-dbk.page-header :subtitle="__('databackd-data-integrations.subtitle')" />

        {{-- 1. SOURCES --}}
        <h2 class="dbk-data-section-title">@lang('databackd-data-integrations.sections.sources')</h2>
        <p class="dbk-data-section-hint">@lang('databackd-data-integrations.sections.sources_hint')</p>

        <div class="row mb-2">
            @foreach ($cards as $card)
                @php
                    $tag = $card['url'] ? 'a' : 'div';
                @endphp
                <div class="col-xl-3 col-md-6 mb-4">
                    <{{ $tag }} @if ($card['url']) href="{{ $card['url'] }}" @endif
                        class="dbk-int-card d-flex flex-column {{ $card['url'] ? 'is-link' : '' }}">
                        <div class="d-flex align-items-center mb-3">
                            <span class="dbk-int-icon mr-3" style="background: {{ $card['color'] }}" aria-hidden="true"><i class="fa fa-{{ $card['icon'] }}"></i></span>
                            <div>
                                <h3 class="dbk-int-title">@lang('databackd-data-integrations.cards.' . $card['key'] . '.title')</h3>
                                @if (!empty($card['locked']))
                                    <span class="dbk-badge">@lang('databackd-data-integrations.badges.locked')</span>
                                @else
                                    <span class="dbk-badge is-good">@lang('databackd-data-integrations.badges.active')</span>
                                @endif
                            </div>
                        </div>
                        <p class="dbk-int-desc flex-grow-1">@lang('databackd-data-integrations.cards.' . $card['key'] . '.desc')</p>
                        @if (!empty($card['locked']))
                            <p class="dbk-int-desc dbk-text-muted f-12 mb-0"><i class="fa fa-lock mr-1" aria-hidden="true"></i>@lang('databackd-data-integrations.badges.locked_hint')</p>
                        @endif
                        @if ($card['url'])
                            <span class="dbk-int-action">@lang('databackd-data-integrations.cards.' . $card['key'] . '.action') <i class="fa fa-arrow-right ml-1 f-11" aria-hidden="true"></i></span>
                        @endif
                    </{{ $tag }}>
                </div>
            @endforeach
        </div>

        {{-- AFV (S8): accounting programs (import templates) and bank statements --}}
        @if (!empty($platformCards))
            <h2 class="dbk-data-section-title">@lang('databackd-gaps-import-templates.sections.platforms')</h2>
            <p class="dbk-data-section-hint">@lang('databackd-gaps-import-templates.sections.platforms_hint')</p>

            <div class="row mb-2">
                @foreach ($platformCards as $card)
                    @php($platformTag = $card['url'] ? 'a' : 'div')
                    <div class="col-xl-3 col-md-6 mb-4">
                        <{{ $platformTag }} @if ($card['url']) href="{{ $card['url'] }}" @endif class="dbk-int-card d-flex flex-column {{ $card['url'] ? 'is-link' : '' }}">
                            <div class="d-flex align-items-center mb-3">
                                <span class="dbk-int-icon mr-3" style="background: {{ $card['color'] }}" aria-hidden="true"><i class="fa fa-{{ $card['icon'] }}"></i></span>
                                <div>
                                    <h3 class="dbk-int-title">@lang('databackd-gaps-import-templates.cards.' . $card['key'] . '.title')</h3>
                                    @if (!empty($card['locked']))
                                        <span class="dbk-badge">@lang('databackd-data-integrations.badges.locked')</span>
                                    @else
                                        <span class="dbk-badge is-good">@lang('databackd-data-integrations.badges.active')</span>
                                    @endif
                                </div>
                            </div>
                            <p class="dbk-int-desc flex-grow-1">@lang('databackd-gaps-import-templates.cards.' . $card['key'] . '.desc')</p>
                            @if (!empty($card['locked']))
                                {{-- AFV (S12): the screen is not in the company's package - no link that would answer 403 --}}
                                <p class="dbk-int-desc dbk-text-muted f-12 mb-0"><i class="fa fa-lock mr-1" aria-hidden="true"></i>@lang('databackd-data-integrations.badges.locked_hint')</p>
                            @else
                                <span class="dbk-int-action">@lang('databackd-gaps-import-templates.cards.' . $card['key'] . '.action') <i class="fa fa-arrow-right ml-1 f-11" aria-hidden="true"></i></span>
                            @endif
                        </{{ $platformTag }}>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- SYNC (package P9) --}}
        @if ($hasSync)
            <div class="dbk-card mb-4 dbk-int-sync">
                <div class="dbk-card-body d-flex flex-wrap align-items-center justify-content-between">
                    <div class="d-flex align-items-start pr-3 mb-2 mb-md-0">
                        <span class="dbk-int-icon is-soft mr-3" aria-hidden="true"><i class="fa fa-sync-alt"></i></span>
                        <div>
                            <h3 class="dbk-int-title">@lang('databackd-data-integrations.sync.title')</h3>
                            <p class="dbk-int-desc mb-0">@lang('databackd-data-integrations.sync.desc')</p>
                        </div>
                    </div>
                    <form id="dbk-sync-form" method="POST" action="{{ route('databackd.data.sync.run') }}" class="d-flex flex-wrap align-items-center">
                        @csrf
                        <button type="submit" class="btn btn-primary rounded f-14 p-2 mr-2" id="dbk-sync-btn">
                            <i class="fa fa-exchange-alt mr-1" aria-hidden="true"></i>@lang('databackd-data-integrations.sync.button')
                        </button>
                        @if (Route::has('databackd.data.sync.index') && databackd_module('databackd_data')) {{-- audit 2026-10-30 (G11) --}}
                            <a href="{{ route('databackd.data.sync.index') }}" class="btn btn-secondary rounded f-14 p-2">@lang('databackd-data-integrations.sync.preview')</a>
                        @endif
                    </form>
                </div>
            </div>
        @endif

        {{-- PLANNED --}}
        @if (!empty($plannedCards))
        <h2 class="dbk-data-section-title">@lang('databackd-data-integrations.sections.planned')</h2>
        <p class="dbk-data-section-hint">@lang('databackd-data-integrations.sections.planned_hint')</p>

        <div class="row mb-2">
            @foreach ($plannedCards as $card)
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="dbk-int-card is-planned d-flex flex-column">
                        <div class="d-flex align-items-center mb-3">
                            <span class="dbk-int-icon mr-3" aria-hidden="true"><i class="fa fa-{{ $card['icon'] }}"></i></span>
                            <div>
                                <h3 class="dbk-int-title">@lang('databackd-data-integrations.planned.' . $card['key'] . '.title')</h3>
                                <span class="dbk-badge is-warning">@lang('databackd-data-integrations.badges.soon')</span>
                            </div>
                        </div>
                        <p class="dbk-int-desc mb-0">@lang('databackd-data-integrations.planned.' . $card['key'] . '.desc')</p>
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        {{-- 2. API ACCESS --}}
        <div id="api-access" class="mt-2">
            <h2 class="dbk-data-section-title">@lang('databackd-data-integrations.api.title')</h2>
            <p class="dbk-data-section-hint">@lang('databackd-data-integrations.api.subtitle')</p>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <x-cards.data :title="__('databackd-data-integrations.tokens.title')" padding="false">
                        <div id="tokens-wrapper" class="p-3">
                            @include('databackd.data.integrations.tokens-table', ['tokens' => $tokens, 'canManage' => $canManageTokens])
                        </div>
                    </x-cards.data>
                </div>
                <div class="col-lg-4 mb-4">
                    <x-cards.data :title="__('databackd-data-integrations.tokens.new')">
                        @if ($canManageTokens)
                            <form id="dbk-token-form" method="POST" action="{{ route('databackd.data.tokens.store') }}" autocomplete="off">
                                @csrf
                                <x-forms.text fieldId="dbk-token-name" fieldName="name"
                                              :fieldLabel="__('databackd-data-integrations.tokens.name')"
                                              :fieldPlaceholder="__('databackd-data-integrations.tokens.name_placeholder')"
                                              :fieldHelp="__('databackd-data-integrations.tokens.name_help')"
                                              :fieldRequired="true" class="mt-0"/>
                                <div class="invalid-feedback d-block text-danger f-12" id="dbk-token-error"></div>
                                <button type="submit" class="btn btn-primary rounded f-14 p-2 mt-2" id="dbk-token-submit">
                                    <i class="fa fa-key mr-1"></i>@lang('databackd-data-integrations.tokens.generate')
                                </button>
                            </form>
                        @else
                            <p class="text-lightest f-13 mb-0">@lang('databackd-data-integrations.tokens.admin_only')</p>
                        @endif
                    </x-cards.data>
                </div>
            </div>

            {{-- 3. DOCUMENTATION --}}
            <x-cards.data :title="__('databackd-data-integrations.docs.title')" class="mb-4">
                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.endpoint')</h6>
                <p class="f-13 text-dark-grey mb-2">@lang('databackd-data-integrations.docs.endpoint_text')</p>
                <pre class="dbk-code"><span class="text-success">POST</span> {{ $endpoint }}</pre>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.headers')</h6>
                <ul class="f-13 text-dark-grey pl-3 mb-0">
                    <li><code>{{ __('databackd-data-integrations.docs.header_auth') }}</code></li>
                    <li><code>{{ __('databackd-data-integrations.docs.header_content') }}</code></li>
                    <li><code>{{ __('databackd-data-integrations.docs.header_accept') }}</code></li>
                    <li>{{ __('databackd-data-integrations.docs.idempotency_text') }}</li>
                </ul>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.body')</h6>
                <p class="f-13 text-dark-grey mb-2">{{ __('databackd-data-integrations.docs.body_text', ['max' => $apiLimits['max']]) }}</p>
                <div class="dbk-code-wrap">
                    <button type="button" class="btn btn-sm btn-light dbk-copy-code f-12" data-target="#dbk-body-example"><i class="fa fa-copy mr-1"></i>@lang('databackd-data-integrations.docs.copy')</button>
                    <pre class="dbk-code" id="dbk-body-example">{{ $bodyExample }}</pre>
                </div>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.options')</h6>
                <p class="f-13 text-dark-grey mb-1"><code>{{ __('databackd-data-integrations.docs.option_create') }}</code></p>
                <p class="f-13 text-dark-grey mb-0">{{ __('databackd-data-integrations.docs.option_create_body', ['dimensions' => $apiLimits['dimensions']]) }}</p>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.curl')</h6>
                <div class="dbk-code-wrap">
                    <button type="button" class="btn btn-sm btn-light dbk-copy-code f-12" data-target="#dbk-curl-example"><i class="fa fa-copy mr-1"></i>@lang('databackd-data-integrations.docs.copy')</button>
                    <pre class="dbk-code" id="dbk-curl-example">{{ $curlExample }}</pre>
                </div>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.response')</h6>
                <p class="f-13 text-dark-grey mb-2">@lang('databackd-data-integrations.docs.response_text')</p>
                <pre class="dbk-code">{{ $responseExample }}</pre>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.codes')</h6>
                <ul class="f-13 text-dark-grey pl-3 mb-0">
                    <li><code>200</code> — @lang('databackd-data-integrations.docs.code_200')</li>
                    <li><code>207</code> — @lang('databackd-data-integrations.docs.code_207')</li>
                    <li><code>400</code> — @lang('databackd-data-integrations.docs.code_400')</li>
                    <li><code>401</code> — @lang('databackd-data-integrations.docs.code_401')</li>
                    <li><code>403</code> — @lang('databackd-data-integrations.docs.code_403')</li>
                    <li><code>405</code> — @lang('databackd-data-integrations.docs.code_405')</li>
                    <li><code>409</code> — @lang('databackd-data-integrations.docs.code_409')</li>
                    <li><code>413</code> — {{ __('databackd-data-integrations.docs.code_413', ['bytes' => number_format($apiLimits['bytes'], 0, '.', ' ')]) }}</li>
                    <li><code>415</code> — @lang('databackd-data-integrations.docs.code_415')</li>
                    <li><code>422</code> — {{ __('databackd-data-integrations.docs.code_422', ['max' => $apiLimits['max'], 'dimensions' => $apiLimits['dimensions']]) }}</li>
                    <li><code>429</code> — {{ __('databackd-data-integrations.docs.code_429', ['per_minute' => $apiLimits['per_minute'], 'per_hour' => number_format($apiLimits['per_hour'], 0, '.', ' '), 'failed_ip' => $apiLimits['failed_ip'], 'per_ip' => $apiLimits['per_ip']]) }}</li>
                    <li><code>500</code> — @lang('databackd-data-integrations.docs.code_500')</li>
                </ul>

                <h6 class="dbk-doc-h">@lang('databackd-data-integrations.docs.fields')</h6>
                <p class="f-13 text-dark-grey mb-2">@lang('databackd-data-integrations.docs.fields_limits')</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover f-13 mb-0">
                        <thead>
                        <tr class="text-dark-grey">
                            <th>@lang('databackd-data-integrations.docs.field_id')</th>
                            <th>@lang('databackd-data-integrations.docs.field_name')</th>
                            <th>@lang('databackd-data-integrations.docs.field_required')</th>
                            <th>@lang('databackd-data-integrations.docs.field_hint')</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($fields as $field)
                            <tr>
                                <td><code>{{ $field['id'] }}</code></td>
                                <td>{{ $field['name'] }}</td>
                                <td>
                                    @if ($field['required'])
                                        <span class="dbk-badge is-bad no-dot">@lang('databackd-data-integrations.docs.yes')</span>
                                    @else
                                        <span class="text-lightest">@lang('databackd-data-integrations.docs.no')</span>
                                    @endif
                                </td>
                                <td class="text-dark-grey">{{ $field['hint'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cards.data>
        </div>
    </div>

    {{-- New token modal --}}
    <div class="modal fade" id="dbk-token-modal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title f-18"><i class="fa fa-check-circle text-success mr-2"></i>@lang('databackd-data-integrations.tokens.modal_title')</h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning f-13 mb-3"><i class="fa fa-exclamation-triangle mr-1"></i><strong>@lang('databackd-data-integrations.tokens.modal_warning')</strong></div>
                    <div class="input-group">
                        <input type="text" class="form-control height-35" id="dbk-token-value" readonly onclick="this.select()">
                        <div class="input-group-append">
                            <button class="btn btn-secondary f-14" type="button" id="dbk-token-copy"><i class="fa fa-copy mr-1"></i>@lang('databackd-data-integrations.tokens.copy')</button>
                        </div>
                    </div>
                    <small class="form-text text-muted">{{ __('databackd-data-integrations.tokens.modal_hint') }}</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary rounded f-14" data-dismiss="modal">@lang('databackd-data-integrations.tokens.close')</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var csrf = '{{ csrf_token() }}';
            var txt = {
                copied: @json(__('databackd-data-integrations.tokens.copied')),
                copy: @json(__('databackd-data-integrations.tokens.copy')),
                tokenFailed: @json(__('databackd-data-integrations.tokens.failed')),
                revokeTitle: @json(__('databackd-data-integrations.tokens.revoke_title')),
                revokeText: @json(__('databackd-data-integrations.tokens.revoke_text')),
                revokeConfirm: @json(__('databackd-data-integrations.tokens.revoke_confirm')),
                cancel: @json(__('databackd-data-integrations.tokens.cancel')),
                syncDone: @json(__('databackd-data-integrations.sync.done')),
                syncFailed: @json(__('databackd-data-integrations.sync.failed')),
                syncRunning: @json(__('databackd-data-integrations.sync.running')),
                syncConfirmTitle: @json(__('databackd-data-sync.confirm_title')),
                syncConfirmText: @json(__('databackd-data-sync.confirm_text')),
                syncConfirmYes: @json(__('databackd-data-sync.confirm_yes'))
            };

            function copyText(text, done) {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
                } else {
                    fallbackCopy(text, done);
                }
            }

            function fallbackCopy(text, done) {
                var ta = $('<textarea readonly>').css({position: 'fixed', top: 0, left: 0, opacity: 0}).val(text).appendTo('body');
                ta[0].select();
                try { document.execCommand('copy'); } catch (e) {}
                ta.remove();
                done();
            }

            function flashButton($btn, html) {
                var old = $btn.html();
                $btn.html(html);
                setTimeout(function () { $btn.html(old); }, 1500);
            }

            // copy code blocks
            $('body').on('click', '.dbk-copy-code', function () {
                var $btn = $(this);
                copyText($($btn.data('target')).text(), function () {
                    flashButton($btn, '<i class="fa fa-check mr-1"></i>' + txt.copied);
                });
            });

            // copy new token
            $('#dbk-token-copy').on('click', function () {
                var $btn = $(this);
                copyText($('#dbk-token-value').val(), function () {
                    flashButton($btn, '<i class="fa fa-check mr-1"></i>' + txt.copied);
                });
            });

            // create token
            $('#dbk-token-form').on('submit', function (e) {
                e.preventDefault();
                var $btn = $('#dbk-token-submit').prop('disabled', true);
                $('#dbk-token-error').text('');
                $('#dbk-token-name').removeClass('is-invalid').removeAttr('aria-invalid');

                $.ajax({
                    url: this.action,
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    success: function (response) {
                        if (response.status === 'success') {
                            $('#tokens-wrapper').html(response.html);
                            $('#dbk-token-name').val('');
                            $('#dbk-token-value').val(response.token);
                            $('#dbk-token-modal').modal('show');
                        } else if (response.errors && response.errors.name) {
                            $('#dbk-token-error').text(response.errors.name[0]);
                            $('#dbk-token-name').addClass('is-invalid').attr('aria-invalid', 'true').trigger('focus');
                        } else {
                            $('#dbk-token-error').text(response.message || txt.tokenFailed);
                        }
                    },
                    error: function (xhr) {
                        var r = xhr.responseJSON || {};
                        var msg = (r.errors && r.errors.name && r.errors.name[0]) || r.message || txt.tokenFailed;
                        $('#dbk-token-error').text(msg);
                        $('#dbk-token-name').addClass('is-invalid').attr('aria-invalid', 'true');
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
            });

            // the plain token must not stay in the DOM after the modal is closed
            $('#dbk-token-modal').on('hidden.bs.modal', function () {
                $('#dbk-token-value').val('');
            });

            // revoke token
            $('body').on('click', '.revoke-token', function () {
                var url = $(this).data('url');

                Swal.fire({
                    title: txt.revokeTitle,
                    text: txt.revokeText,
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: txt.revokeConfirm,
                    cancelButtonText: txt.cancel,
                    customClass: {confirmButton: 'btn btn-danger mr-3', cancelButton: 'btn btn-secondary'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (!(result.isConfirmed || result.value)) {
                        return;
                    }

                    $.ajax({
                        url: url,
                        type: 'POST',
                        data: {_method: 'DELETE', _token: csrf},
                        dataType: 'json',
                        headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                        success: function (response) {
                            if (response.status === 'success') {
                                $('#tokens-wrapper').html(response.html);
                            }
                        }
                    });
                });
            });

            // sync from invoices and expenses (package P9): JSON Reply or a redirect
            $('#dbk-sync-form').on('submit', function (e) {
                e.preventDefault();
                var form = this;

                // the run writes, updates and removes financial entries - same confirmation as on the sync page
                Swal.fire({
                    title: txt.syncConfirmTitle,
                    text: txt.syncConfirmText,
                    icon: 'question',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: txt.syncConfirmYes,
                    cancelButtonText: txt.cancel,
                    customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.isConfirmed || result.value) {
                        runSync(form);
                    }
                });
            });

            function runSync(form) {
                var $btn = $('#dbk-sync-btn').prop('disabled', true);
                var oldHtml = $btn.html();
                $btn.html('<i class="fa fa-spinner fa-spin mr-1"></i>' + txt.syncRunning);

                function notify(icon, message, after) {
                    Swal.fire({icon: icon, text: message, confirmButtonText: 'OK'}).then(function () {
                        if (after) { after(); }
                    });
                }

                $.ajax({
                    url: form.action,
                    type: 'POST',
                    data: $(form).serialize(),
                    dataType: 'text',
                    headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    success: function (body) {
                        var r = null;
                        try { r = JSON.parse(body); } catch (err) { r = null; }

                        if (r === null) {
                            // run() always answers JSON; an HTML page means a followed redirect (expired session / CSRF token -> login)
                            notify('error', txt.syncFailed, function () { window.location.reload(); });
                        } else if (r.status === 'fail') {
                            notify('error', r.message || txt.syncFailed);
                        } else {
                            notify('success', r.message || txt.syncDone, function () {
                                if (r.action === 'redirect' && r.url) {
                                    window.location.href = r.url;
                                }
                            });
                        }
                    },
                    error: function (xhr) {
                        var r = xhr.responseJSON || {};
                        notify('error', r.message || txt.syncFailed);
                    },
                    complete: function () {
                        $btn.prop('disabled', false).html(oldHtml);
                    }
                });
            }
        });
    </script>
@endpush
