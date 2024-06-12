{{-- TEMPORARY: fallback of the shared P5 partial databackd.data.import._mapping (same contract). Delete when P5's partial is used everywhere. --}}
{{-- Variables: $headers string[], $rows list of rows, $fields = FinEntryImporter::fields(), $mapping = [fieldId => columnIndex] --}}
@php
    $selected = collect($mapping ?? [])->flip();
    $prefix = $formPrefix ?? 'columns';
@endphp
<div class="table-responsive">
    <table class="table table-hover border-0 w-100 mb-0">
        <thead class="thead-light">
        <tr>
            <th class="f-13 text-dark-grey" style="width: 4%">#</th>
            <th class="f-13 text-dark-grey" style="width: 26%">@lang('databackd-data-sources.fallback.column')</th>
            <th class="f-13 text-dark-grey" style="width: 40%">@lang('databackd-data-sources.fallback.example')</th>
            <th class="f-13 text-dark-grey" style="width: 30%">@lang('databackd-data-sources.fallback.field')</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($headers as $index => $header)
            <tr>
                <td class="f-13 text-lightest">{{ $index + 1 }}</td>
                <td class="f-14 f-w-500 text-dark">{{ $header }}</td>
                <td class="f-13 text-dark-grey">
                    @foreach (array_slice(array_column($rows, $index), 0, 3) as $value)
                        <span class="badge badge-light border mr-1 mb-1 f-12 f-w-400">{{ \Illuminate\Support\Str::limit((string)$value, 28) }}</span>
                    @endforeach
                </td>
                <td>
                    <select name="{{ $prefix }}[{{ $index }}]" class="form-control form-control-sm f-14">
                        <option value="">@lang('databackd-data-sources.fallback.skip')</option>
                        @foreach ($fields as $field)
                            <option value="{{ $field['id'] }}" @selected(($selected[$index] ?? '') === $field['id'])>
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
<p class="f-12 text-lightest mt-2 mb-0">* @lang('databackd-data-sources.fallback.required')</p>
