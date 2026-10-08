<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A page of a list in the shape of docs/api/README.md section 3: `data` and a `meta` of
 * `page`, `per_page` and `total` (no links, no other key).
 */
final class PageOf extends ResourceCollection
{
    /**
     * A page of records, each shown as this resource.
     *
     * @param  LengthAwarePaginator<int, covariant Model>  $page
     * @param  class-string<ApiResource>  $resource
     */
    public static function from(LengthAwarePaginator $page, string $resource): self
    {
        return new self($page->through(fn ($record) => new $resource($record)));
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'page' => $paginated['current_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
            ],
        ];
    }
}
