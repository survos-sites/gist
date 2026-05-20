<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Lemma;
use Doctrine\ORM\QueryBuilder;
use Mezcalito\UxSearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractFieldSearch;

#[AsSearch(index: Lemma::class, adapter: 'default')]
final class LemmaSearch extends AbstractFieldSearch
{
    protected function getFieldClass(array $options = []): string
    {
        return Lemma::class;
    }

    public function build(array $options = []): void
    {
        parent::build($options);

        $this->setAdapterParameters(array_replace($this->getAdapterParameters(), [
            DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
            DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $qb): void {},
            DoctrineAdapter::SEARCH_FIELDS => $this->getAdapterParameters()['searchFields'] ?? ['headword', 'pos'],
        ]));
    }
}
