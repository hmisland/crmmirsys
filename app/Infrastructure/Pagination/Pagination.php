<?php

declare(strict_types=1);

namespace App\Infrastructure\Pagination;

use App\Core\Request;

/**
 * Page/per-page state for one list, read from ?page= & ?per_page=,
 * plus the current query string so page links keep the active filters.
 *
 * Two ways to use it:
 *  - Database-side: Pagination::fromRequest($request, $total) after a
 *    COUNT, then pass ->perPage / ->offset() to the repository's LIMIT.
 *  - In memory, for lists already loaded whole: Pagination::slice($rows, $request).
 *
 * Templates render it with partials/pagination.php.
 */
final class Pagination
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    public const DEFAULT_PER_PAGE = 25;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        /** @var array<string, mixed> current query parameters (filters) */
        public readonly array $query,
    ) {
    }

    public static function fromRequest(Request $request, int $total): self
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        return new self($page, $perPage, max(0, $total), $request->allQuery());
    }

    /**
     * Paginates rows that were already loaded.
     *
     * @return array{0: array, 1: self} the current page's rows, and the pagination
     */
    public static function slice(array $rows, Request $request): array
    {
        $pagination = self::fromRequest($request, count($rows));

        return [array_slice(array_values($rows), $pagination->offset(), $pagination->perPage), $pagination];
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /** 1-based index of the first row on this page (0 when empty). */
    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }

    /** Query string for another page, keeping every current filter. */
    public function url(int $page): string
    {
        $query = $this->query;
        $query['page'] = $page;
        if ($this->perPage !== self::DEFAULT_PER_PAGE) {
            $query['per_page'] = $this->perPage;
        }
        if ($page === 1) {
            unset($query['page']);
        }

        return $query === [] ? '?' : '?' . http_build_query($query);
    }

    /**
     * Page numbers to show, with null as a gap: 1 … 4 5 [6] 7 8 … 20.
     *
     * @return array<int, int|null>
     */
    public function window(int $around = 2): array
    {
        $last = $this->lastPage();
        $pages = [];

        for ($p = 1; $p <= $last; $p++) {
            if ($p === 1 || $p === $last || abs($p - $this->page) <= $around) {
                $pages[] = $p;
            } elseif (end($pages) !== null) {
                $pages[] = null;
            }
        }

        return $pages;
    }

    /** Current filters except paging — for hidden inputs in the per-page form. */
    public function filterQuery(): array
    {
        return array_diff_key($this->query, ['page' => true, 'per_page' => true]);
    }
}
