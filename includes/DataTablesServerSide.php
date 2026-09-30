<?php
/**
 * Server-side processing engine for DataTables (https://datatables.net) ajax
 * requests.
 */

/**
 * Read a DataTables ajax GET parameter.
 */
function dtParam(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/**
 * Run one server-side DataTables query: total count, filtered count, and
 * the one page of rows that matches the current search/order/pagination.
 *
 * $config:
 *   conn      (required) mysqli connection
 *   select    (required) raw SQL column list (no "SELECT " prefix)
 *   from      (required) raw SQL "table JOIN table ..." (no "FROM " prefix)
 *   where     (optional) raw SQL condition (no "WHERE " prefix), always applied
 *   group_by  (optional) raw SQL (no "GROUP BY " prefix)
 *   columns   (required) list, ONE ENTRY PER <thead> COLUMN, IN ORDER:
 *               ['db' => 's.nama', 'search' => true, 'order' => true]
 *             pass 'db' => null for columns with no backing SQL column
 *             (action buttons, computed badges, etc).
 *
 * Returns ['draw', 'recordsTotal', 'recordsFiltered', 'rows'].
 */
function runServerSideQuery(array $config): array
{
    $conn = $config['conn'];
    $select = $config['select'];
    $from = $config['from'];
    $baseWhere = $config['where'] ?? '1=1';
    $groupBy = $config['group_by'] ?? '';
    $columns = $config['columns'];

    $draw = (int) dtParam('draw', 1);
    $start = max(0, (int) dtParam('start', 0));
    $length = (int) dtParam('length', 10);
    // Hard ceiling so ?length=999999 can't force a full table scan/dump.
    $length = $length > 0 ? min($length, 200) : 10;

    $groupSuffix = $groupBy !== '' ? " GROUP BY $groupBy" : '';

    // --- total (unfiltered, but honoring any fixed base WHERE) ---
    $totalSql = "SELECT COUNT(*) AS c FROM (SELECT 1 FROM $from WHERE $baseWhere$groupSuffix) t";
    $totalRes = mysqli_query($conn, $totalSql);
    $recordsTotal = $totalRes ? (int) (mysqli_fetch_assoc($totalRes)['c'] ?? 0) : 0;

    // --- search WHERE (Multi-term / multi-column search) ---
    $searchWhere = $baseWhere;
    $searchValue = trim((string) (dtParam('search')['value'] ?? ''));
    if ($searchValue !== '') {
        // Pecah input pencarian berdasarkan spasi menjadi kata-kata terpisah
        $terms = preg_split('/\s+/', $searchValue, -1, PREG_SPLIT_NO_EMPTY);
        $termConditions = [];

        foreach ($terms as $term) {
            $escaped = mysqli_real_escape_string($conn, $term);
            $searchable = [];
            foreach ($columns as $col) {
                if (!empty($col['search']) && !empty($col['db'])) {
                    $searchable[] = "{$col['db']} LIKE '%$escaped%'";
                }
            }
            if (!empty($searchable)) {
                $termConditions[] = '(' . implode(' OR ', $searchable) . ')';
            }
        }

        if (!empty($termConditions)) {
            $searchWhere .= ' AND ' . implode(' AND ', $termConditions);
        }
    }

    $filteredSql = "SELECT COUNT(*) AS c FROM (SELECT 1 FROM $from WHERE $searchWhere$groupSuffix) t";
    $filteredRes = mysqli_query($conn, $filteredSql);
    $recordsFiltered = $filteredRes ? (int) (mysqli_fetch_assoc($filteredRes)['c'] ?? 0) : 0;

    // --- order ---
    $orderSql = '';
    $order = dtParam('order');
    if (is_array($order) && isset($order[0]['column'])) {
        $colIndex = (int) $order[0]['column'];
        $dir = (isset($order[0]['dir']) && strtolower((string) $order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        if (isset($columns[$colIndex]) && !empty($columns[$colIndex]['order']) && !empty($columns[$colIndex]['db'])) {
            $orderSql = "ORDER BY {$columns[$colIndex]['db']} $dir";
        }
    }
    if ($orderSql === '') {
        foreach ($columns as $col) {
            if (!empty($col['order']) && !empty($col['db'])) {
                $orderSql = "ORDER BY {$col['db']} ASC";
                break;
            }
        }
    }

    $sql = "SELECT $select FROM $from WHERE $searchWhere$groupSuffix $orderSql LIMIT $start, $length";
    $result = mysqli_query($conn, $sql);

    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    return [
        'draw' => $draw,
        'start' => $start,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'rows' => $rows,
    ];
}

/**
 * Wrap the final `data` rows into the exact shape DataTables expects.
 */
function buildDataTablesResponse(array $queryResult, array $data): array
{
    return [
        'draw' => $queryResult['draw'],
        'recordsTotal' => $queryResult['recordsTotal'],
        'recordsFiltered' => $queryResult['recordsFiltered'],
        'data' => $data,
    ];
}