<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Exceptions;

use RuntimeException;

/** Un webhook dont la signature ne correspond pas, ou trop ancien. */
final class InvalidSignatureException extends RuntimeException {}
