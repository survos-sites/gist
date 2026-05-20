<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\FreeDictCatalog;
use App\Service\TeiImportService;
use App\Workflow\IFreeDictCatalogWorkflow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class FreeDictCatalogWorkflow
{
    public function __construct(
        private readonly TeiImportService $teiImportService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    private function getCatalog(TransitionEvent|GuardEvent $event): FreeDictCatalog
    {
        /** @var FreeDictCatalog */
        return $event->getSubject();
    }

    #[AsCompletedListener(WF::WORKFLOW_NAME)]
    public function onCompleted(CompletedEvent $event): void
    {
        $this->em->flush();
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_DOWNLOAD)]
    public function onDownload(TransitionEvent $event): void
    {
        $catalog = $this->getCatalog($event);

        $this->teiImportService->downloadTei($catalog);

        $this->logger->info("TEI downloaded for {$catalog->name}.");
    }

    #[AsGuardListener(WF::WORKFLOW_NAME, WF::TRANSITION_PROCESS)]
    public function guardProcess(GuardEvent $event): void
    {
        $catalog = $this->getCatalog($event);
        $path = $this->teiImportService->teiXmlPath($catalog);

        if (!\is_file($path) || \filesize($path) === 0) {
            $event->setBlocked(true, "TEI file not downloaded yet for {$catalog->name}.");
        }
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_PROCESS)]
    public function onProcess(TransitionEvent $event): void
    {
        $catalog = $this->getCatalog($event);
        $count = 0;

        $this->teiImportService->processTei(
            $catalog,
            progress: function (int $n) use (&$count) {
                $count = $n;
                if (0 === $n % 1000) {
                    $this->logger->info("Imported $n entries…");
                }
            }
        );

        $this->logger->info("TEI processed for {$catalog->name}: $count entries.");
    }
}
