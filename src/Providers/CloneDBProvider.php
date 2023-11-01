<?php

namespace InigoPascall\CloneDB\Providers;

use Illuminate\Support\ServiceProvider;

class CloneDBProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/clone-db.php' => config_path('clone-db.php'),
        ], 'clone-db');

        $this->commands([
            \InigoPascall\CloneDB\Commands\CloneDB ::class,
        ]);
    }
}
