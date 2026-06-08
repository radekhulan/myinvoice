<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

final readonly class ExportPeriod
{
    public function __construct(
        public string $type,
        public int $year,
        public ?int $month,
        public ?int $quarter,
        public string $dateFrom,
        public string $dateToExclusive,
        public string $label,
    ) {}

    public function isQuarterly(): bool
    {
        return $this->type === 'quarterly';
    }
}
