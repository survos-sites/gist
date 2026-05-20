<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\FreeDictCatalog;
use Doctrine\ORM\QueryBuilder;
use Mezcalito\UxSearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractFieldSearch;

#[AsSearch(index: FreeDictCatalog::class, adapter: 'default')]
final class FreeDictCatalogSearch extends AbstractFieldSearch
{
    protected function getFieldClass(array $options = []): string
    {
        return FreeDictCatalog::class;
    }

    public function build(array $options = []): void
    {
        parent::build($options);

        $this->setAdapterParameters(array_replace($this->getAdapterParameters(), [
            DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
            DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $qb): void {},
            DoctrineAdapter::SEARCH_FIELDS => $this->getAdapterParameters()['searchFields'] ?? ['name', 'src', 'dst', 'status'],
        ]));
    }
}
