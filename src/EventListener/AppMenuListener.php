<?php

declare(strict_types=1);

namespace App\EventListener;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;
use Survos\TablerBundle\Service\IconService;

final class AppMenuListener
{
    use MenuBuilderTrait;

    public function __construct(
        protected readonly ?RouterInterface $router = null,
        protected readonly ?RouteAliasService $routeAliasService = null,
        protected readonly ?IconService $iconService = null,
    ) {}

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function onNavbar(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, 'app_lookup', label: 'Lookup');
        $this->add($menu, 'status_dashboard', label: 'Status');
        $this->add($menu, 'admin', label: 'Admin');
    }

    #[AsEventListener(event: MenuEvent::SIDEBAR)]
    public function onSidebar(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, 'app_lookup', label: 'Word Lookup', icon: 'tabler:search');
        $this->add($menu, 'status_dashboard', label: 'Status', icon: 'tabler:chart-bar');
        $this->add($menu, 'survos_workflows', label: 'Workflows', icon: 'tabler:git-branch');
        $this->add($menu, 'admin', label: 'Admin', icon: 'tabler:settings');
    }
}
