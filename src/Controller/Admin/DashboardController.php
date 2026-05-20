<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/ez', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirectToRoute('admin_free_dict_catalog_index');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('FreeDict');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkTo(FreeDictCatalogCrudController::class, 'Catalog');
        yield MenuItem::linkTo(DictionaryCrudController::class, 'Dictionaries');
        yield MenuItem::linkTo(LanguageCrudController::class, 'Languages');
        yield MenuItem::linkTo(LemmaCrudController::class, 'Lemmas');
        yield MenuItem::linkTo(SenseCrudController::class, 'Senses');
        yield MenuItem::linkTo(TranslationCrudController::class, 'Translations');
    }
}
