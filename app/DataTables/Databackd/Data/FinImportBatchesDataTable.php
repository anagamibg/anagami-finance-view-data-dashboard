<?php

namespace App\DataTables\Databackd\Data;

use App\DataTables\BaseDataTable;
use App\Helper\Common;
use App\Http\Controllers\Databackd\Data\FinImportBatchController;
use App\Models\Databackd\FinImportBatch;
use Illuminate\Support\Str;
use Yajra\DataTables\Html\Column;

class FinImportBatchesDataTable extends BaseDataTable
{

    public function dataTable($query)
    {
        $datatables = datatables()->eloquent($query);

        $datatables->editColumn('id', function ($row) {
            return '<a href="' . route('databackd.data.batches.show', $row->id) . '" class="text-darkest-grey f-w-500">#' . $row->id . '</a>';
        });

        $datatables->editColumn('created_at', function ($row) {
            $date = $row->created_at?->timezone($this->company->timezone);

            return $date
                ? '<span class="text-darkest-grey">' . $date->translatedFormat($this->company->date_format) . '</span>'
                    . '<span class="text-lightest f-12 d-block">' . $date->format('H:i') . '</span>'
                : '--';
        });

        $datatables->editColumn('source', fn($row) => view('databackd.data.batches._source', ['source' => $row->source])->render());

        $datatables->addColumn('file', function ($row) {
            if ($row->file_name) {
                return '<span title="' . e($row->file_name) . '"><i class="fa fa-file-alt text-lightest mr-1"></i>' . e(Str::limit($row->file_name, 40)) . '</span>';
            }

            if ($row->source_url) {
                $short = preg_replace('#^https?://(www\.)?#i', '', $row->source_url);

                return '<span title="' . e($row->source_url) . '"><i class="fa fa-link text-lightest mr-1"></i>' . e(Str::limit($short, 40)) . '</span>';
            }

            return '<span class="text-lightest">' . __('databackd-data-batches.no_file') . '</span>';
        });

        $datatables->editColumn('rows_total', fn($row) => '<span class="text-darkest-grey">' . number_format((int)$row->rows_total, 0, ',', ' ') . '</span>');

        $datatables->editColumn('rows_imported', function ($row) {
            $class = $row->rows_imported > 0 ? 'text-success' : 'text-lightest';

            return '<span class="f-w-500 ' . $class . '">' . number_format((int)$row->rows_imported, 0, ',', ' ') . '</span>';
        });

        $datatables->editColumn('rows_failed', function ($row) {
            $class = $row->rows_failed > 0 ? 'text-danger f-w-500' : 'text-lightest';

            return '<span class="' . $class . '">' . number_format((int)$row->rows_failed, 0, ',', ' ') . '</span>';
        });

        $datatables->editColumn('status', fn($row) => view('databackd.data.batches._status', ['status' => $row->status])->render());

        $datatables->addColumn('user_name', function ($row) {
            if ($row->source === 'api') {
                return '<i class="fa fa-key text-lightest mr-1"></i>' . __('databackd-data-batches.api_token');
            }

            return $row->user_name ? e($row->user_name) : '<span class="text-lightest">' . __('databackd-data-batches.system') . '</span>';
        });

        $datatables->addColumn('action', function ($row) {
            $action = '<div class="task_view"><div class="dropdown">
                <a class="task_view_more d-flex align-items-center justify-content-center dropdown-toggle" type="link"
                    id="dropdownMenuLink-' . $row->id . '" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="icon-options-vertical icons"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuLink-' . $row->id . '" tabindex="0">';

            $action .= '<a href="' . route('databackd.data.batches.show', $row->id) . '" class="dropdown-item"><i class="fa fa-eye mr-2"></i>' . __('databackd-data-batches.actions.view') . '</a>';

            if (FinImportBatchController::canUndo($row)) {
                $action .= '<a class="dropdown-item undo-batch" href="javascript:;" data-batch-id="' . $row->id . '" data-entries="' . (int)$row->rows_imported . '"><i class="fa fa-undo mr-2"></i>' . __('databackd-data-batches.actions.undo') . '</a>';
            }

            return $action . '</div></div></div>';
        });

        $datatables->smart(false);
        \App\DataTables\Databackd\ColumnWhitelist::apply($datatables, $this->getColumns()); // S6/P3: search / order only by the declared columns
        $datatables->setRowId(fn($row) => 'row-' . $row->id);
        $datatables->rawColumns(['id', 'created_at', 'source', 'file', 'rows_total', 'rows_imported', 'rows_failed', 'status', 'user_name', 'action']);

        return $datatables;
    }

    public function query()
    {
        $request = $this->request();

        $model = FinImportBatch::query()
            ->leftJoin('users', 'users.id', '=', 'fin_import_batches.created_by')
            ->select('fin_import_batches.id', 'fin_import_batches.source', 'fin_import_batches.file_name', 'fin_import_batches.source_url', 'fin_import_batches.rows_total', 'fin_import_batches.rows_imported', 'fin_import_batches.rows_failed', 'fin_import_batches.status', 'fin_import_batches.created_by', 'fin_import_batches.created_at', 'users.name as user_name');

        // S6/P3: a malformed date (or an array) in the query string is ignored instead of ending in a 500
        $dateString = function ($value) {
            try {
                return is_string($value) ? companyToDateString($value) : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        if ($request->startDate !== null && $request->startDate != 'null' && $request->startDate != '' && ($from = $dateString($request->startDate))) {
            $model->whereDate('fin_import_batches.created_at', '>=', $from);
        }

        if ($request->endDate !== null && $request->endDate != 'null' && $request->endDate != '' && ($to = $dateString($request->endDate))) {
            $model->whereDate('fin_import_batches.created_at', '<=', $to);
        }

        if ($request->source != 'all' && !is_null($request->source) && $request->source != '') {
            $model->where('fin_import_batches.source', $request->source);
        }

        if ($request->status != 'all' && !is_null($request->status) && $request->status != '') {
            $model->where('fin_import_batches.status', $request->status);
        }

        if ($request->searchText != '') {
            $term = Common::safeString($request->searchText);

            $model->where(function ($query) use ($term) {
                $query->where('fin_import_batches.file_name', 'like', '%' . $term . '%')
                    ->orWhere('fin_import_batches.source_url', 'like', '%' . $term . '%')
                    ->orWhere('users.name', 'like', '%' . $term . '%')
                    ->orWhere('fin_import_batches.id', 'like', '%' . $term . '%');
            });
        }

        return $model;
    }

    public function html()
    {
        return $this->setBuilder('batches-table', 1);
    }

    protected function getColumns()
    {
        return [
            __('databackd-data-batches.columns.number') => ['data' => 'id', 'name' => 'fin_import_batches.id', 'title' => __('databackd-data-batches.columns.number')],
            __('databackd-data-batches.columns.date') => ['data' => 'created_at', 'name' => 'fin_import_batches.created_at', 'title' => __('databackd-data-batches.columns.date')],
            __('databackd-data-batches.columns.source') => ['data' => 'source', 'name' => 'fin_import_batches.source', 'title' => __('databackd-data-batches.columns.source')],
            __('databackd-data-batches.columns.file') => ['data' => 'file', 'name' => 'fin_import_batches.file_name', 'title' => __('databackd-data-batches.columns.file'), 'orderable' => false],
            __('databackd-data-batches.columns.rows_total') => ['data' => 'rows_total', 'name' => 'fin_import_batches.rows_total', 'title' => __('databackd-data-batches.columns.rows_total'), 'className' => 'text-right'],
            __('databackd-data-batches.columns.rows_imported') => ['data' => 'rows_imported', 'name' => 'fin_import_batches.rows_imported', 'title' => __('databackd-data-batches.columns.rows_imported'), 'className' => 'text-right'],
            __('databackd-data-batches.columns.rows_failed') => ['data' => 'rows_failed', 'name' => 'fin_import_batches.rows_failed', 'title' => __('databackd-data-batches.columns.rows_failed'), 'className' => 'text-right'],
            __('databackd-data-batches.columns.status') => ['data' => 'status', 'name' => 'fin_import_batches.status', 'title' => __('databackd-data-batches.columns.status')],
            __('databackd-data-batches.columns.user') => ['data' => 'user_name', 'name' => 'users.name', 'title' => __('databackd-data-batches.columns.user')],
            Column::computed('action', __('databackd-data-batches.columns.actions'))
                ->exportable(false)
                ->printable(false)
                ->orderable(false)
                ->searchable(false)
                ->addClass('text-right pr-20'),
        ];
    }

}
