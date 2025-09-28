<?php

namespace App\Workflow;

use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;

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

	#[Transition(from: [self::PLACE_NEW], to: self::PLACE_DOWNLOADED)]
	public const TRANSITION_DOWNLOAD = 'download';

	#[Transition(from: [self::PLACE_DOWNLOADED], to: self::PLACE_PROCESSED)]
	public const TRANSITION_PROCESS = 'process';
}
