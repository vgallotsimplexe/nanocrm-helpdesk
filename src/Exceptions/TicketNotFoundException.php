<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Exceptions;

/** Ticket inconnu, ou que cette personne ne voit pas. */
final class TicketNotFoundException extends HelpdeskException {}
