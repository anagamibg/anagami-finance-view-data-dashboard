<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Data\FinDimension\StoreFinDimension;
use App\Http\Requests\Databackd\Data\FinDimension\UpdateFinDimension;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensions (regions, client segments, product groups, cost centers): one screen with a tab per type.
 */
class FinDimensionController extends AccountBaseController
{

    /** fin_entries column that references each dimension type */
    const ENTRY_COLUMNS = [
        'region' => 'region_id',
        'client_segment' => 'client_segment_id',
        'product_group' => 'product_group_id',
        'cost_center' => 'cost_center_id',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-dimensions.title');
            abort_403(in_array('client', user_roles()));
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $activeType = $request->query('type');
        $this->activeType = in_array($activeType, FinDimension::TYPES) ? $activeType : FinDimension::TYPES[0];

        $dimensions = FinDimension::orderBy('name')->get()->groupBy('type');
        $dimensionsByType = [];
        $totalsByType = [];

        foreach (FinDimension::TYPES as $type) {
            $column = self::ENTRY_COLUMNS[$type];

            // One grouped query per type: number of entries per dimension value
            $counts = FinEntry::whereNotNull($column)
                ->selectRaw($column . ' as dim_id, count(*) as cnt')
                ->groupBy($column)
                ->pluck('cnt', 'dim_id');

            $items = $dimensions->get($type, collect())->each(function ($item) use ($counts) {
                $item->entries_count = (int)($counts[$item->id] ?? 0);
            });

            $dimensionsByType[$type] = $items;
            $totalsByType[$type] = $items->sum('entries_count');
        }

        $this->dimensionsByType = $dimensionsByType;
        $this->totalsByType = $totalsByType;

        return view('databackd.data.dimensions.index', $this->data);
    }

    public function create(Request $request)
    {
        $type = $request->query('type');
        $type = in_array($type, FinDimension::TYPES) ? $type : FinDimension::TYPES[0];

        $this->type = $type;
        $this->dimension = null;

        return view('databackd.data.dimensions.form', $this->data);
    }

    public function store(StoreFinDimension $request)
    {
        $dimension = new FinDimension();
        $dimension->company_id = company()->id;
        $dimension->type = $request->type;
        $dimension->name = trim($request->name);
        $dimension->code = filled($request->code) ? trim($request->code) : null;
        $dimension->save();

        return Reply::successWithData(__('databackd-data-dimensions.dimension_saved'), ['type' => $dimension->type]);
    }

    public function edit($id)
    {
        $this->dimension = FinDimension::findOrFail($id);
        $this->type = $this->dimension->type;

        return view('databackd.data.dimensions.form', $this->data);
    }

    public function update(UpdateFinDimension $request, $id)
    {
        $dimension = FinDimension::findOrFail($id);
        $dimension->name = trim($request->name);
        $dimension->code = filled($request->code) ? trim($request->code) : null;
        $dimension->save();

        return Reply::successWithData(__('databackd-data-dimensions.dimension_saved'), ['type' => $dimension->type]);
    }

    public function destroy($id)
    {
        $dimension = FinDimension::findOrFail($id);
        $used = FinEntry::where(self::ENTRY_COLUMNS[$dimension->type], $dimension->id)->count();

        if ($used > 0) {
            return Reply::error(__('databackd-data-dimensions.dimension_in_use', ['count' => $used]));
        }

        // S12/A07: budget lines keep region / product group / cost center - a deleted value would leave them orphaned
        $budgetColumn = ['region' => 'region_id', 'product_group' => 'product_group_id', 'cost_center' => 'cost_center_id'][$dimension->type] ?? null;
        $budgetLines = $budgetColumn && Schema::hasTable('fin_budget_lines')
            ? DB::table('fin_budget_lines')->where('company_id', company()->id)->where($budgetColumn, $dimension->id)->count()
            : 0;

        if ($budgetLines > 0) {
            return Reply::error(__('databackd-data-dimensions.dimension_in_budgets', ['count' => $budgetLines]));
        }

        $dimension->delete();

        return Reply::success(__('databackd-data-dimensions.dimension_deleted'));
    }

}
