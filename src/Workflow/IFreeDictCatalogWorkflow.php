<?php

namespace App\Workflow;

use App\Entity\FreeDictCatalog;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [FreeDictCatalog::class], name: self::WORKFLOW_NAME)]
class IFreeDictCatalogWorkflow
{
    public const WORKFLOW_NAME = 'FreeDictCatalogWorkflow';

	#[Place(initial: true)]
	public const PLACE_NEW = 'new';

	#[Place]
	public const PLACE_DOWNLOADED = 'downloaded';

	#[Place]
	public const PLACE_PROCESSED = 'processed';

	#[Transition(from: [self::PLACE_NEW],
        to: self::PLACE_DOWNLOADED,
        async: true)]
	public const TRANSITION_DOWNLOAD = 'download';

	#[Transition(from: [self::PLACE_DOWNLOADED], to: self::PLACE_PROCESSED, async: true)]
	public const TRANSITION_PROCESS = 'process';
}
