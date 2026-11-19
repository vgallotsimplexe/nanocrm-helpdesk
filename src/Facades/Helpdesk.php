<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Facades;

use Illuminate\Support\Facades\Facade;
use Nanocrm\Helpdesk\Data\ConnectionInfo;
use Nanocrm\Helpdesk\HelpdeskClient;

/**
 * @method static HelpdeskClient as(string $email, ?string $name = null)
 * @method static ConnectionInfo check()
 * @method static bool isConfigured()
 * @method static void resolveKeyUsing(?\Closure $resolver)
 * @method static void resolveUrlUsing(?\Closure $resolver)
 * @method static string textToHtml(string $text)
 *
 * @see \Nanocrm\Helpdesk\Helpdesk
 */
final class Helpdesk extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nanocrm\Helpdesk\Helpdesk::class;
    }
}
