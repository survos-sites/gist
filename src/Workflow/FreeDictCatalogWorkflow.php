<?php

namespace App\Workflow;

use App\Entity\FreeDictCatalog;
use App\Service\TeiImportService;
use Psr\Log\LoggerInterface;
use Survos\WorkflowBundle\Attribute\Workflow;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;

#[Workflow(supports: [FreeDictCatalog::class], name: self::WORKFLOW_NAME)]
class FreeDictCatalogWorkflow implements IFreeDictCatalogWorkflow
{
	public const WORKFLOW_NAME = 'FreeDictCatalogWorkflow';

	public function __construct(
        private TeiImportService $teiImportService,
        private LoggerInterface $logger,
    )
	{
	}


	public function getFreeDictCatalog(TransitionEvent|GuardEvent $event): FreeDictCatalog
	{
		/** @var FreeDictCatalog */ return $event->getSubject();
	}

    // this is really process..
	#[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_DOWNLOAD)]
	public function onDownload(TransitionEvent $event): void
	{
		$freeDictCatalog = $this->getFreeDictCatalog($event);
        $dict = $this->teiImportService->importTei($freeDictCatalog,
//            truncate: $force,
//            limit: $limit,
            progress: function (int $n) use (&$count) {
                $count = $n;
                if ($n % 1000 === 0) $this->logger->info("Imported $n entries…");
            }
        );
	}


	#[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_PROCESS)]
	public function onProcess(TransitionEvent $event): void
	{
		$freeDictCatalog = $this->getFreeDictCatalog($event);
	}
}
