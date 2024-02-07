{{--
    Shared column-mapping table (used by the Excel/CSV wizard and by Google Sheets / CSV URL sources).

    Must be included INSIDE a <form>. Variables:
      $headers     string[]                   column headings of the file/sheet
      $rows        array[]                    first data rows (lists indexed by column); the first 5 are used for the sample values
      $fields      array                      result of FinEntryImporter::fields()
      $mapping     array<string,int>          canonical [fieldId => columnIndex] (e.g. FinEntryImporter::guessMapping())
      $previewUrl  string|null (optional)     POST route for the live preview; without it there is no live validation
      $formPrefix  string (optional)          name prefix of the selects, default "columns" => columns[<columnIndex>] = fieldId|''

    Live preview contract: on load and on every change the whole enclosing form is POSTed (serialize()) to $previewUrl; the endpoint answers JSON
    {"html": "<rendered table>"} (see _preview.blade.php) or {"status":"error","message":"..."}. Event "finimport:preview" is triggered on the root element.
--}}
@php
    $formPrefix = $formPrefix ?? 'columns';
    $previewUrl = $previewUrl ?? null;
    $uid = 'finmap' . \Illuminate\Support\Str::random(6);
    $columnField = array_flip(array_map('intval', $mapping ?? []));
    $sampleRows = array_slice($rows ?? [], 0, 5);
    $requiredFields = array_values(array_filter($fields, fn($f) => $f['required']));
    $virtualColumns = $virtualColumns ?? []; // AFV (S8): indexes of the columns that a platform template computed
@endphp

<div class="fin-import-mapping" id="{{ $uid }}" @if ($previewUrl) data-preview-url="{{ $previewUrl }}" @endif>
    <div class="fin-map-required">
        <span class="fin-map-required-label">{{ __('databackd-data-import.mapping.required_title') }}:</span>
        @foreach ($requiredFields as $field)
            <span class="badge badge-pill fin-required-badge badge-danger" data-required-field="{{ $field['id'] }}">
                <i class="fa fa-times mr-1"></i>{{ $field['name'] }}
            </span>
        @endforeach
    </div>

    <div class="dbk-table-wrap">
        <table class="dbk-table dbk-table--compact fin-map-table">
            <thead>
                <tr>
                    <th class="num" style="width: 40px;">#</th>
                    <th style="width: 24%;">{{ __('databackd-data-import.mapping.col_file') }}</th>
                    <th>{{ __('databackd-data-import.mapping.col_samples') }}</th>
                    <th style="width: 28%;">{{ __('databackd-data-import.mapping.col_field') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($headers as $index => $header)
                    @php
                        $selected = $columnField[$index] ?? '';
                        $samples = collect($sampleRows)->map(fn($row) => $row[$index] ?? null)
                            ->filter(fn($v) => $v !== null && trim((string)$v) !== '')->values();
                    @endphp
                    <tr class="fin-map-row {{ $selected !== '' ? 'is-mapped' : '' }}">
                        <td class="num dbk-text-muted">{{ $index + 1 }}</td>
                        <td class="f-w-500">
                            {{ $header }}
                            @if (in_array($index, $virtualColumns, true))
                                <span class="badge badge-info ml-1">{{ __('databackd-gaps-import-templates.mapping.computed_badge') }}</span>
                            @endif
                        </td>
                        <td>
                            @forelse ($samples as $value)
                                <span class="fin-map-sample" title="{{ $value }}">{{ \Illuminate\Support\Str::limit((string)$value, 40) }}</span>
                            @empty
                                <span class="fin-map-sample empty">{{ __('databackd-data-import.mapping.empty_column') }}</span>
                            @endforelse
                        </td>
                        <td>
                            <select class="form-control form-control-sm fin-map-select" name="{{ $formPrefix }}[{{ $index }}]">
                                <option value="">{{ __('databackd-data-import.mapping.skip') }}</option>
                                @foreach ($fields as $field)
                                    <option value="{{ $field['id'] }}" @selected($selected === $field['id'])>
                                        {{ $field['name'] }}{{ $field['required'] ? ' *' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="fin-map-count" data-role="mapped-count"></div>

    @if ($previewUrl)
        <div class="mt-4" data-role="preview"></div>
    @endif
</div>

@push('scripts')
    <script>
        (function () {
            var $root = $('#{{ $uid }}');
            var previewUrl = $root.data('preview-url');
            var $preview = $root.find('[data-role="preview"]');
            var $form = $root.closest('form');
            var timer = null, xhr = null;
            var total = {{ count($headers) }};
            @php($mappedCountText = __('databackd-data-import.mapping.mapped_count', ['mapped' => ':mapped', 'total' => ':total']))
            var labels = {
                loading: @json(__('databackd-data-import.preview.loading')),
                error: @json(__('databackd-data-import.preview.error')),
                mapped: @json($mappedCountText)
            };

            function sync() {
                var used = {}, mapped = 0;
                var $selects = $root.find('select.fin-map-select');

                $selects.each(function () {
                    var v = $(this).val();
                    if (v) { used[v] = this; mapped++; }
                    $(this).closest('tr').toggleClass('is-mapped', !!v);
                });

                // one field can be chosen for one column only
                $selects.each(function () {
                    var self = this;
                    $(this).find('option').each(function () {
                        var v = $(this).val();
                        if (v) { $(this).prop('disabled', !!used[v] && used[v] !== self); }
                    });
                });

                $root.find('[data-required-field]').each(function () {
                    var ok = !!used[$(this).data('required-field')];
                    $(this).toggleClass('badge-success', ok).toggleClass('badge-danger', !ok);
                    $(this).find('i').attr('class', ok ? 'fa fa-check mr-1' : 'fa fa-times mr-1');
                });

                $root.find('[data-role="mapped-count"]').text(labels.mapped.replace(':mapped', mapped).replace(':total', total));
            }

            function load() {
                if (!previewUrl) { return; }
                var data = $form.serialize();
                if (data.indexOf('_token=') < 0) { data += '&_token=' + encodeURIComponent('{{ csrf_token() }}'); }
                if (xhr) { xhr.abort(); }

                $preview.html('<div class="text-lightest f-13"><i class="fa fa-spinner fa-spin mr-1"></i>' + labels.loading + '</div>');

                xhr = $.ajax({url: previewUrl, type: 'POST', data: data, dataType: 'json'})
                    .done(function (response) {
                        if (response && response.html) {
                            $preview.html(response.html);
                        } else {
                            $preview.html('<div class="alert alert-warning mb-0">' + $('<div>').text((response && response.message) || labels.error).html() + '</div>');
                        }
                        $root.trigger('finimport:preview', [response]);
                    })
                    .fail(function (jqXHR, status) {
                        if (status !== 'abort') {
                            $preview.html('<div class="alert alert-warning mb-0">' + labels.error + '</div>');
                        }
                    });
            }

            function refresh() {
                clearTimeout(timer);
                timer = setTimeout(load, 300);
            }

            $root.on('change', 'select.fin-map-select', function () { sync(); refresh(); });
            // other inputs of the same form (default entity, "create missing dimensions"...) also affect the validation
            $form.on('change', ':input', function (e) {
                if (!$(e.target).hasClass('fin-map-select') && $(e.target).attr('name') !== '_token') { refresh(); }
            });

            sync();
            load();
        })();
    </script>
@endpush
