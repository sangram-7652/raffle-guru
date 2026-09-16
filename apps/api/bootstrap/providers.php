<?php

use App\Providers\AppServiceProvider;
use App\Providers\DrawServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    PaymentServiceProvider::class,
    DrawServiceProvider::class,
    AdminPanelProvider::class,
];
