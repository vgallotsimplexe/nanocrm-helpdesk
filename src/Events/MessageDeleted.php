<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Events;

/** `message.deleted` — Un message public a été retiré par le support ; son uuid est dans messageUuid(). */
final class MessageDeleted extends HelpdeskEvent
{
    public function messageUuid(): ?string
    {
        return isset($this->payload['message_uuid']) ? (string) $this->payload['message_uuid'] : null;
    }
}
