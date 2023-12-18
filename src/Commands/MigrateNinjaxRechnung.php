<?php

namespace Webvision\NinjaZugferd\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MigrateNinjaxRechnung extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:ninja-xrechnung';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add new fields in the ninja table for xRechnung';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::warning("Testing");
        $this->info('Running migrations for NinjaxRechnung package...');

        // Run the migrations for the NinjaxRechnung package
        $this->call('migrate', [
            '--path' => 'vendor/webvision/ninja-xrechnung/src/database/migrations',
        ]);

        Log::warning("Bushra");
        $this->info('Migrations for NinjaxRechnung package completed.');
    }
}
