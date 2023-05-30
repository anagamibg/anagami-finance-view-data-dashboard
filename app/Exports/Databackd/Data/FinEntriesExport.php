<?php

namespace App\Exports\Databackd\Data;

use App\DataTables\Databackd\Data\FinEntriesDataTable;
use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of fin_entries with the same filters as the list (see FinEntriesDataTable::filteredQuery()).
 */
class FinEntriesExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithStyles, WithTitle, ShouldAutoSize
{

    use NeutralizesFormulas;

    public function __construct(protected Request $request)
    {
    }

    public function query()
    {
        return FinEntriesDataTable::filteredQuery($this->request)
            ->orderBy('fin_entries.entry_date')
            ->orderBy('fin_entries.id');
    }

    public function title(): string
    {
        return mb_substr(__('databackd-data-entries.export_title'), 0, 31);
    }

    public function headings(): array
    {
        $c = fn(string $key) => __('databackd-data-entries.columns.' . $key);

        return [
            $c('date'),
            $c('entity'),
            __('databackd-data-common.fields.account_code'),
            $c('account'),
            $c('account_type'),
            $c('kind'),
            $c('amount'),
            $c('currency'),
            $c('amount_base'),
            $c('region'),
            $c('client_segment'),
            $c('product_group'),
            $c('cost_center'),
            $c('counterparty'),
            $c('document_no'),
            $c('description'),
            $c('source'),
            $c('batch'),
        ];
    }

    /**
     * @param \App\Models\Databackd\FinEntry $row
     */
    public function map($row): array
    {
        // S6/P4: neutralizeRow() guards the user-controlled text columns (entity/account/dimension names,
        // counterparty, document_no, description) against CSV/Excel formula injection; numeric/date columns are
        // untouched (it only ever rewrites strings).
        return self::neutralizeRow([
            $row->entry_date ? Date::dateTimeToExcel($row->entry_date) : null,
            $row->entity_name,
            $row->account_code,
            $row->account_name,
            __('databackd-data-common.account_types.' . $row->account_type),
            __('databackd-data-common.kinds.' . $row->kind),
            (float)$row->amount,
            $row->currency,
            (float)$row->amount_base,
            $row->region_name,
            $row->segment_name,
            $row->product_name,
            $row->cost_center_name,
            $row->counterparty_name,
            $row->document_no,
            $row->description,
            __('databackd-data-common.sources.' . $row->source),
            $row->import_batch_id,
        ]);
    }

    public function columnFormats(): array
    {
        return [
            'A' => 'dd.mm.yyyy',
            'C' => NumberFormat::FORMAT_TEXT,
            'G' => '#,##0.00',
            'I' => '#,##0.00',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

}
