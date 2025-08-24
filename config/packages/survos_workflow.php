<?php

declare(strict_types=1);

use Survos\WorkflowBundle\Service\ConfigureFromAttributesService;
use Symfony\Config\FrameworkConfig;

// modeled after knp_dictionary.php
//return static function (ContainerConfigurator $containerConfigurator): void {
//    $containerConfigurator->extension('knp_dictionary', [

return static function (FrameworkConfig $framework) {
//return static function (ContainerConfigurator $containerConfigurator): void {

    foreach ([
        \App\Workflow\FreeDictCatalogWorkflow::class,
             ] as $workflowClass) {
        ConfigureFromAttributesService::configureFramework($workflowClass, $framework, [$workflowClass]);
    }

};
