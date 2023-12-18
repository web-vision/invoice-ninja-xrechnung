<?php

namespace Webvision\NinjaZugferd\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        Log::warning("Testing");
        $this->publishes([
            __DIR__.'/database/migrations/' => database_path('migrations')
        ], 'migrations');

        Log::warning(__DIR__);
    }
}
