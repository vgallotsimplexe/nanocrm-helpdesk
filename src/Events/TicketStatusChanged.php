<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Events;

/** `ticket.status_changed` — Le statut a changé ; l'ancien et le nouveau sont dans from() et to(). */
final class TicketStatusChanged extends HelpdeskEvent
{
    public function from(): ?string
    {
        return $this->payload['change']['from'] ?? null;
    }

    public function to(): string
    {
        return $this->payload['change']['to'] ?? $this->ticket->status;
    }

    /** Pourquoi, quand ce n'est pas un geste explicite : `client_replied`, `resolved_by_client`… */
    public function reason(): ?string
    {
        return $this->payload['change']['reason'] ?? null;
    }
}
