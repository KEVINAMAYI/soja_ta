<?php

namespace App\Helpers;

use App\Models\UserActivityLog;
use App\Utils\ApiConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaginationHelper
{
    public const DEFAULT_PAGE_SIZE = 15;
    public const MAX_PAGE_SIZE = 100;

    /**
     * Build normalized page/per_page/sort params from a request, logging and
     * rejecting attempts to request more than the max page size.
     *
     * @return array{page: int, per_page: int, sort_field: ?string, sort_direction: string}
     *
     * @throws ValidationException
     */
    public static function build(
        Request $request,
        int $defaultPageSize = self::DEFAULT_PAGE_SIZE,
        int $maxPageSize = self::MAX_PAGE_SIZE
    ): array {
        $page = max((int) $request->input('page', 1), 1);
        $perPage = (int) $request->input('per_page', $defaultPageSize);

        if ($perPage > $maxPageSize) {
            UserActivityLog::storeUserActivityLog(
                ApiConstants::USER_MALICIOUS_ACTIVITY,
                "Requested per_page of {$perPage} exceeds the maximum allowed of {$maxPageSize}."
            );

            throw ValidationException::withMessages([
                'per_page' => "You cannot request more than {$maxPageSize} elements.",
            ]);
        }

        if ($perPage < 1) {
            $perPage = $defaultPageSize;
        }

        [$sortField, $sortDirection] = self::parseSort($request->input('sort'));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
        ];
    }

    /**
     * Apply the built sort/page params to a query builder and paginate it.
     */
    public static function paginate(
        Builder $query,
        Request $request,
        int $defaultPageSize = self::DEFAULT_PAGE_SIZE,
        int $maxPageSize = self::MAX_PAGE_SIZE
    ): LengthAwarePaginator {
        $params = self::build($request, $defaultPageSize, $maxPageSize);

        if ($params['sort_field']) {
            $query->orderBy($params['sort_field'], $params['sort_direction']);
        }

        return $query->paginate(
            $params['per_page'],
            ['*'],
            'page',
            $params['page']
        );
    }

    /**
     * Parse a "field,direction" sort string, defaulting direction to asc.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function parseSort(?string $sort): array
    {
        if (!$sort) {
            return [null, 'asc'];
        }

        $parts = explode(',', $sort);
        $direction = (isset($parts[1]) && strtolower($parts[1]) === 'desc') ? 'desc' : 'asc';

        return [$parts[0], $direction];
    }
}
