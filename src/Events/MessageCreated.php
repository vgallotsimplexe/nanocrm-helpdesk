<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Events;

/** `message.created` — Un message public : une réponse du support (message->isFromAgent()), ou d'une personne de l'outil. */
final class MessageCreated extends HelpdeskEvent
{}
