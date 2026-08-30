<?php

declare(strict_types=1);

namespace App\Support;

final class Pagination
{
    /** @return array{page:int,per_page:int,per_page_param:string,total:int,total_pages:int,offset:int,limit:?int} */
    public static function fromRequest(int $total, int $defaultPerPage = 25): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPageParam = (string) ($_GET['per_page'] ?? (string) $defaultPerPage);

        $perPage = match ($perPageParam) {
            '50' => 50,
            '100' => 100,
            'all', '0' => $total > 0 ? $total : 1,
            default => $defaultPerPage,
        };

        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'per_page_param' => $perPageParam,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'limit' => $perPage,
        ];
    }

    /** @param array<string, scalar|null> $extra */
    public static function queryString(array $extra = []): string
    {
        $params = array_merge($_GET, $extra);
        unset($params['page']);
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            }
        }

        return http_build_query($params);
    }
}
