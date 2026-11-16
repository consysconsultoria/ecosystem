<?php

namespace Consys\Ecosystem\Tests;

use Consys\Ecosystem\Consys;
use Consys\Ecosystem\Contracts\ConsysSignIn;
use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\EcosystemServiceProvider;
use Consys\Ecosystem\Tests\Fixtures\FakeSignIn;
use Consys\Ecosystem\Tests\Fixtures\RecordingMirror;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EcosystemServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'consys.auth.mode' => 'optional',
            'consys.auth.url' => 'https://auth.test/',
            'consys.auth.client_id' => 'erp-client',
            'consys.auth.client_secret' => 'erp-client-secret',
            'consys.directory.application' => 'erp',
            'consys.directory.admin_url' => 'https://admin.test',
            'consys.directory.secret' => 'directory-secret',
        ]);

        $app->singleton(RecordingMirror::class);
        $app->singleton(FakeSignIn::class);
        $app->bind(DirectoryMirror::class, RecordingMirror::class);
        $app->bind(ConsysSignIn::class, FakeSignIn::class);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Consys::webhookRoute();
            Consys::authRoutes();
            Consys::logoutRoute();

            Route::get('/login', fn (): string => 'login')->name('login');
            Route::get('/home', fn (): string => 'home')->name('home');
        });
    }
}
