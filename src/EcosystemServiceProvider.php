<?php

namespace Consys\Ecosystem;

use Consys\Ecosystem\Console\SyncDirectoryCommand;
use Consys\Ecosystem\Contracts\ConsysSignIn;
use Consys\Ecosystem\Contracts\DirectoryMirror;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

class EcosystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/consys.php', 'consys');

        $this->app->bindIf(DirectoryMirror::class, fn (Application $app): DirectoryMirror => $app->make(
            $this->configuredClass('consys.directory.mirror', DirectoryMirror::class),
        ));

        $this->app->bindIf(ConsysSignIn::class, fn (Application $app): ConsysSignIn => $app->make(
            $this->configuredClass('consys.auth.handler', ConsysSignIn::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/consys.php' => config_path('consys.php')], 'consys-config');

            $this->commands([SyncDirectoryCommand::class]);
        }
    }

    /**
     * @param  class-string  $contract
     * @return class-string
     */
    private function configuredClass(string $key, string $contract): string
    {
        $class = config($key);

        if (! is_string($class) || $class === '') {
            throw new LogicException("Bind {$contract} in a service provider or set {$key} to the class that implements it.");
        }

        return $class;
    }
}
