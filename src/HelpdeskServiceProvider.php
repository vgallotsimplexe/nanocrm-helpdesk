<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nanocrm\Helpdesk\Webhooks\WebhookController;

final class HelpdeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/helpdesk.php', 'helpdesk');

        // Un seul objet par application : il ne garde ni clé ni adresse, il
        // les relit à chaque appel.
        $this->app->singleton(Helpdesk::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/helpdesk.php' => config_path('helpdesk.php')], 'helpdesk-config');

        $path = config('helpdesk.webhook.path');

        if (is_string($path) && $path !== '' && ! $this->app->routesAreCached()) {
            Route::post($path, WebhookController::class)
                ->middleware((array) config('helpdesk.webhook.middleware', ['api']))
                ->name('nanocrm-helpdesk.webhook');
        }
    }
}
