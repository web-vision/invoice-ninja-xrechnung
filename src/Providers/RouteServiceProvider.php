<?php

namespace Webvision\NinjaZugferd\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        Log::warning(__DIR__);
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
