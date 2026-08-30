<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\Enum\IndexAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class IndexNameFactory
{
    public function __construct(
        #[Autowire(param: 'elasticsearch_index_suffix')]
        private string $indexSuffix,
    ) {
    }

    public function build(IndexAlias $alias): string
    {
        return $alias->value . $this->indexSuffix;
    }
}
