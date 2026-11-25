<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;

final class StaffDashboardController extends Controller
{
    public function show(): void
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        $this->render('staff/dashboard', [
            'title' => 'Staff dashboard',
            'user' => $user,
            'navigation' => [
                ['route' => 'dashboard', 'label' => 'Dashboard'],
                ['route' => 'products', 'label' => 'Products'],
                ['route' => 'inventory-receipts', 'label' => 'Stock receipts'],
                ['route' => 'sales', 'label' => 'Sales'],
                ['route' => 'payments', 'label' => 'Payments'],
                ['route' => 'categories', 'label' => 'Categories'],
                ['route' => 'units', 'label' => 'Units'],
                ['route' => 'change-password', 'label' => 'Password'],
            ],
        ]);
    }
}
