<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * nanoCRM a refusé l'appel. `errorCode` est le code stable de nanoCRM
 * (`helpdesk.unauthenticated`, `ticket.closed`, `ticket.requester_not_removable`…),
 * à tester plutôt que le message, qui est rédigé pour un humain.
 */
class HelpdeskException extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors  erreurs de validation, champ par champ */
    public function __construct(
        string $message,
        public readonly ?string $errorCode,
        public readonly int $status,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $message = (string) ($body['title'] ?? $body['message'] ?? "nanoCRM a répondu {$response->status()}.");
        $code = isset($body['code']) ? (string) $body['code'] : null;
        /** @var array<string, list<string>> $errors */
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

        return match (true) {
            $response->status() === 401 => new UnauthenticatedException($message, $code, 401),
            $response->status() === 404 => new TicketNotFoundException($message, $code, 404),
            $response->status() === 422 && $errors !== [] => new ValidationException($message, $code ?? 'validation', 422, $errors),
            default => new self($message, $code, $response->status(), $errors),
        };
    }
}
