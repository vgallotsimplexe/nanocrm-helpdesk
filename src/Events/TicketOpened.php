<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Events;

/** `ticket.opened` — Un ticket a été ouvert (depuis l'outil ou par le support). Porte le premier message. */
final class TicketOpened extends HelpdeskEvent
{}
