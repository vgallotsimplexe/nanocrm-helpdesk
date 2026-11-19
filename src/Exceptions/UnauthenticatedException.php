<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Exceptions;

/** Clé absente, inconnue ou révoquée : vérifiez NANOCRM_HELPDESK_KEY. */
final class UnauthenticatedException extends HelpdeskException {}
