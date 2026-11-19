<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Tests;

use Nanocrm\Helpdesk\HelpdeskServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const KEY = 'nhk_cle_de_test_0123456789';

    protected function getPackageProviders($app): array
    {
        return [HelpdeskServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('helpdesk.url', 'https://acme.nanocrm.test/support/v1');
        $app['config']->set('helpdesk.key', self::KEY);
        $app['config']->set('cache.default', 'array');
    }

    /** @return array<string, mixed> un ticket tel que nanoCRM le rend */
    protected static function ticket(int $revision = 1, array $extra = []): array
    {
        return [
            'uuid' => '9a0c3e1e-0000-4000-8000-000000000001',
            'number' => 'SU2026-0001',
            'reference' => 'LFT-42',
            'subject' => 'Export bloqué',
            'status' => 'open',
            'status_label' => 'Ouvert',
            'priority' => 'normal',
            'revision' => $revision,
            'participants' => [
                ['uuid' => 'p1', 'role' => 'requester', 'email' => 'jean@acme.fr', 'name' => 'Jean'],
                ['uuid' => 'p2', 'role' => 'cc', 'email' => 'paul@acme.fr', 'name' => null],
            ],
            'created_at' => '2026-11-19T10:00:00+00:00',
            'last_activity_at' => '2026-11-19T10:00:00+00:00',
            'resolved_at' => null,
            'closed_at' => null,
            ...$extra,
        ];
    }
}
