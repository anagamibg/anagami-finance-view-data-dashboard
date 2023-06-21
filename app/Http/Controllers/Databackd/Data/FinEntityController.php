<?php

namespace App\Http\Controllers\Databackd\Data;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Data\FinEntity\StoreFinEntity;
use App\Http\Requests\Databackd\Data\FinEntity\UpdateFinEntity;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal entities of the group (parent / subsidiary tree with ownership percentage).
 */
class FinEntityController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-data-dimensions.entities_title');
            abort_403(in_array('client', user_roles()));
            return $next($request);
        });
    }

    public function index()
    {
        $entities = FinEntity::orderBy('sort')->orderBy('name')->get();
        $counts = FinEntry::selectRaw('entity_id, count(*) as cnt')->groupBy('entity_id')->pluck('cnt', 'entity_id');

        $tree = $this->buildTree($entities, $counts);

        $this->tree = $tree;
        $this->rows = $this->flatten($tree);
        $this->names = $entities->pluck('name', 'id');
        $this->totalEntities = $entities->count();
        $this->activeEntities = $entities->where('is_active', true)->count();
        $this->rootCount = count($tree);
        $this->totalEntries = (int)$counts->sum();

        return view('databackd.data.entities.index', $this->data);
    }

    public function create()
    {
        $this->entity = null;
        $this->parentOptions = $this->parentOptions(null);
        $this->nextSort = (int)FinEntity::max('sort') + 1;
        $this->currencyCodes = FinEntryController::currencyCodes();

        return view('databackd.data.entities.form', $this->data);
    }

    public function store(StoreFinEntity $request)
    {
        $entity = new FinEntity();
        $entity->company_id = company()->id;
        $this->fill($entity, $request);
        $entity->save();

        return Reply::success(__('databackd-data-dimensions.entity_saved'));
    }

    public function edit($id)
    {
        $this->entity = FinEntity::findOrFail($id);
        $this->parentOptions = $this->parentOptions($this->entity);
        $this->nextSort = (int)$this->entity->sort;
        $this->currencyCodes = FinEntryController::currencyCodes($this->entity->currency);

        return view('databackd.data.entities.form', $this->data);
    }

    public function update(UpdateFinEntity $request, $id)
    {
        $entity = FinEntity::findOrFail($id);
        $this->fill($entity, $request);
        $entity->save();

        return Reply::success(__('databackd-data-dimensions.entity_saved'));
    }

    public function destroy($id)
    {
        $entity = FinEntity::findOrFail($id);

        $entries = FinEntry::where('entity_id', $entity->id)->count();

        if ($entries > 0) {
            return Reply::error(__('databackd-data-dimensions.entity_has_entries', ['count' => $entries]));
        }

        $children = FinEntity::where('parent_id', $entity->id)->count();

        if ($children > 0) {
            return Reply::error(__('databackd-data-dimensions.entity_has_children', ['count' => $children]));
        }

        // S12/A07: budgets, forecasts, alert rules, bank statements, sources and counterparty rules point to the legal
        // entity (the consolidation groups would lose it silently - their link is deleted by a cascade)
        if ($usage = $this->otherUsage($entity->id)) {
            return Reply::error(__('databackd-data-dimensions.entity_in_use', ['list' => $usage]));
        }

        $entity->delete();

        return Reply::success(__('databackd-data-dimensions.entity_deleted'));
    }

    /**
     * Other records that refer to the legal entity, as a readable list ("" = none).
     */
    private function otherUsage(int $entityId): string
    {
        $companyId = company()->id;
        $tables = [
            'budgets' => ['fin_budgets', 'entity_id'],
            'forecasts' => ['fin_forecasts', 'entity_id'],
            'consolidation_groups' => ['fin_consolidation_group_entities', 'entity_id'],
            'alert_rules' => ['fin_alert_rules', 'entity_id'],
            'bank_statements' => ['fin_bank_statements', 'entity_id'],
            'data_sources' => ['fin_data_sources', 'default_entity_id'],
            'counterparty_rules' => ['fin_counterparty_mappings', 'entity_id'],
        ];
        $usage = [];

        foreach ($tables as $key => [$table, $column]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table)->where($column, $entityId);

            // the link table of the consolidation groups has no company_id: the entity id is already of this company
            if (Schema::hasColumn($table, 'company_id')) {
                $query->where('company_id', $companyId);
            }

            if (($count = $query->count()) > 0) {
                $usage[] = __('databackd-data-dimensions.usage.' . $key, ['count' => $count]);
            }
        }

        return implode(', ', $usage);
    }

    private function fill(FinEntity $entity, $request): void
    {
        $entity->name = trim(preg_replace('/\s+/u', ' ', (string)$request->name));
        $entity->eik = filled($request->eik) ? trim($request->eik) : null;
        $entity->country = strtoupper($request->country);
        $entity->currency = strtoupper($request->currency);
        $entity->parent_id = $request->filled('parent_id') ? (int)$request->parent_id : null;
        $entity->ownership_pct = $request->filled('ownership_pct') ? (float)$request->ownership_pct : 100;
        $entity->is_active = $request->boolean('is_active');
        $entity->sort = $request->filled('sort') ? (int)$request->sort : ((int)FinEntity::max('sort') + 1);
    }

    /**
     * Entities that may be chosen as parent: everything except the entity itself and all of its descendants.
     */
    private function parentOptions(?FinEntity $entity)
    {
        $all = FinEntity::orderBy('sort')->orderBy('name')->get();

        if (!$entity) {
            return $all;
        }

        $excluded = [$entity->id => true];
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($all as $item) {
                if (!isset($excluded[$item->id]) && $item->parent_id && isset($excluded[$item->parent_id])) {
                    $excluded[$item->id] = true;
                    $changed = true;
                }
            }
        }

        return $all->reject(fn($item) => isset($excluded[$item->id]))->values();
    }

    /**
     * Nested structure: [['entity' => FinEntity, 'entries' => int, 'effective' => float|null, 'children' => [...]], ...]
     * 'effective' is the group's effective ownership (product of the percentages along the chain), null for roots.
     */
    private function buildTree($entities, $counts): array
    {
        $byParent = $entities->groupBy(fn($item) => $item->parent_id ?: 0);
        $ids = $entities->pluck('id')->flip();
        $visited = [];

        $build = function ($parentKey, $effectiveParent) use (&$build, $byParent, $counts, &$visited) {
            $nodes = [];

            foreach ($byParent->get($parentKey, collect()) as $entity) {
                if (isset($visited[$entity->id])) {
                    continue;
                }

                $visited[$entity->id] = true;
                $effective = $parentKey === 0 ? null : round(($effectiveParent ?? 100) * $entity->ownership_pct / 100, 2);

                $nodes[] = [
                    'entity' => $entity,
                    'entries' => (int)($counts[$entity->id] ?? 0),
                    'effective' => $effective,
                    'children' => $build($entity->id, $effective ?? 100),
                ];
            }

            return $nodes;
        };

        // Roots: no parent, or a parent that is not visible (defensive)
        $tree = $build(0, null);

        foreach ($entities as $entity) {
            if (!isset($visited[$entity->id]) && $entity->parent_id && !isset($ids[$entity->parent_id])) {
                $visited[$entity->id] = true;
                $tree[] = ['entity' => $entity, 'entries' => (int)($counts[$entity->id] ?? 0), 'effective' => null, 'children' => $build($entity->id, 100)];
            }
        }

        return $tree;
    }

    /**
     * Depth-first flat list for the table: [['node' => ..., 'depth' => int, 'parent' => ?FinEntity], ...]
     */
    private function flatten(array $tree, int $depth = 0): array
    {
        $rows = [];

        foreach ($tree as $node) {
            $rows[] = ['node' => $node, 'depth' => $depth];
            $rows = array_merge($rows, $this->flatten($node['children'], $depth + 1));
        }

        return $rows;
    }

}
