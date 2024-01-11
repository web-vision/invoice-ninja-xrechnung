<?php

namespace Webvision\NinjaZugferd\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Webvision\NinjaZugferd\Commands\MigrateNinjaxRechnung;

class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
