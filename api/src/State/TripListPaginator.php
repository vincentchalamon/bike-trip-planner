<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\State\Pagination\PaginatorInterface;
use App\ApiResource\TripListItem;

/**
 * SQL-level paginator for the trip list, supports an external total item count
 * so that LIMIT/OFFSET can be applied at the database level instead of fetching
 * the entire collection into memory.
 *
 * @implements \IteratorAggregate<mixed, TripListItem>
 * @implements PaginatorInterface<TripListItem>
 */
final readonly class TripListPaginator implements \IteratorAggregate, PaginatorInterface
{
    /** @param list<TripListItem> $items */
    public function __construct(
        private array $items,
        private float $currentPage,
        private float $itemsPerPage,
        private float $totalItems,
    ) {
    }

    public function getCurrentPage(): float
    {
        return $this->currentPage;
    }

    public function getItemsPerPage(): float
    {
        return $this->itemsPerPage;
    }

    public function getLastPage(): float
    {
        if ($this->itemsPerPage <= 0) {
            return 1.0;
        }

        return ceil($this->totalItems / $this->itemsPerPage) ?: 1.0;
    }

    public function getTotalItems(): float
    {
        return $this->totalItems;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}
