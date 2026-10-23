<?php
declare(strict_types=1);

namespace BharatSeo\Support;

final class Paginator
{
    public readonly int $page;
    public readonly int $perPage;
    public readonly int $total;
    public readonly int $pages;

    public function __construct(int $total, int $page, int $perPage = 25)
    {
        $this->perPage = max(1, min(200, $perPage));
        $this->total = max(0, $total);
        $this->pages = max(1, (int)ceil($this->total / $this->perPage));
        $this->page = max(1, min($page, $this->pages));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limitSql(): string
    {
        return ' LIMIT ' . $this->perPage . ' OFFSET ' . $this->offset();
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }
}
