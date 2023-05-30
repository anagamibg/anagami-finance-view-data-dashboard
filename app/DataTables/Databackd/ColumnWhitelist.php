<?php

namespace App\DataTables\Databackd;

/**
 * Restricts the per-column search and the ordering of a AFV DataTable to the columns the table itself declares
 * in getColumns() (S6/P3).
 *
 * yajra DataTables takes the column to search / order by from the request (columns[i][name] / columns[i][data]). It
 * quotes the name (no SQL injection), but with the default whitelist "*" any column of any joined table qualifies, e.g.
 * columns[0][name]=users.register_ip with columns[0][search][value]=... turns the list into a yes/no oracle over data
 * that the screen never shows. With a whitelist, a column that is not declared is simply ignored.
 *
 * Usage, in DataTable::dataTable(): ColumnWhitelist::apply($datatables, $this->getColumns());
 */
class ColumnWhitelist
{

    /**
     * @param \Yajra\DataTables\DataTableAbstract $datatables
     * @param array $columns the array returned by getColumns() (arrays with data / name, or Yajra\DataTables\Html\Column)
     */
    public static function apply($datatables, array $columns): void
    {
        $names = [];

        foreach ($columns as $column) {
            $name = is_array($column)
                ? ($column['name'] ?? $column['data'] ?? null)
                : ($column->name ?? $column->data ?? null);

            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        $datatables->whitelist(array_keys($names));
    }

}
