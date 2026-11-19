<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Events;

use DateTimeImmutable;
use Nanocrm\Helpdesk\Data\Actor;
use Nanocrm\Helpdesk\Data\Message;
use Nanocrm\Helpdesk\Data\Ticket;

/**
 * Un webhook de nanoCRM, vérifié et dédoublonné. Écoutez `HelpdeskWebhook`
 * pour tout recevoir, ou un événement précis (`MessageCreated`…).
 *
 * `stale` : un webhook plus récent pour ce ticket est déjà passé (rejeu tardif
 * après une panne). Son `message` reste à prendre, mais ne remplacez pas
 * l'état du ticket par son `ticket`, qui est plus ancien.
 */
abstract class HelpdeskEvent implements HelpdeskWebhook
{
    /** @param  array<string, mixed>  $payload  le webhook brut */
    public function __construct(
        public readonly string $event,
        public readonly string $delivery,
        public readonly Ticket $ticket,
        public readonly ?Message $message,
        public readonly ?Actor $actor,
        public readonly DateTimeImmutable $occurredAt,
        public readonly bool $stale,
        public readonly array $payload,
    ) {}

    /**
     * Les personnes à prévenir : les participants, sauf l'auteur du changement.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_values(array_filter(
            $this->ticket->participantEmails(),
            fn (string $email): bool => $this->actor === null || ! $this->actor->is($email),
        ));
    }
}
