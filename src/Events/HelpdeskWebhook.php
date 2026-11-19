<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Events;

/**
 * Tous les webhooks de nanoCRM. Laravel prévient les écouteurs d'une
 * interface, pas ceux d'une classe parente : écoutez celle-ci pour tout
 * recevoir d'un coup.
 *
 *     Event::listen(HelpdeskWebhook::class, NotifyTicketParticipants::class);
 */
interface HelpdeskWebhook {}
