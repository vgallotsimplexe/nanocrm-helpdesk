<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Exceptions;

use RuntimeException;

/** NANOCRM_HELPDESK_URL ou NANOCRM_HELPDESK_KEY manque pour cette requête. */
final class ConfigurationException extends RuntimeException {}
