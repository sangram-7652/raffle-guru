<?php

namespace App\Providers;

use App\Services\Draw\Strategies\AutomatedDrawStrategy;
use App\Services\Draw\Strategies\DrawStrategy;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the draw engine's strategy interface, same pattern as
 * PaymentServiceProvider binding PaymentGatewayInterface (P6). Only one
 * concrete strategy exists today (AutomatedDrawStrategy) so this is a
 * fixed binding rather than config-driven — when a second strategy
 * (LiveDrawStrategy/InstantWinStrategy) exists, this is where a
 * per-competition/per-draw-type resolution decision belongs. See
 * docs/DRAW_ENGINE_P9.md "Future draw types".
 */
class DrawServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DrawStrategy::class, AutomatedDrawStrategy::class);
    }
}
