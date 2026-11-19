<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Nanocrm\Helpdesk\Data\Message;
use Nanocrm\Helpdesk\Data\Ticket;
use Nanocrm\Helpdesk\Data\TicketPage;
use Nanocrm\Helpdesk\Exceptions\HelpdeskException;
use Throwable;

/**
 * Les gestes d'une personne de l'outil sur ses tickets. S'obtient par
 * `Helpdesk::as($email, $name)`.
 *
 * Erreurs : `HelpdeskException` et ses filles (`UnauthenticatedException`,
 * `TicketNotFoundException`, `ValidationException`), avec le code stable de
 * nanoCRM dans `errorCode` (`ticket.closed`…).
 */
final class HelpdeskClient
{
    public function __construct(
        private readonly Helpdesk $helpdesk,
        private readonly string $email,
        private readonly ?string $name = null,
        private readonly bool $companyWide = false,
    ) {}

    /**
     * Tous les tickets de l'outil, pas seulement ceux de la personne. À
     * réserver aux administrateurs de l'outil : c'est lui qui en décide.
     */
    public function companyWide(): self
    {
        return new self($this->helpdesk, $this->email, $this->name, true);
    }

    /**
     * @param  string|null  $status  `active` (ouverts et en attente), un statut, plusieurs séparés par des virgules, ou null pour tous
     */
    public function tickets(?string $status = 'active', int $page = 1, int $perPage = 25, ?string $reference = null): TicketPage
    {
        /** @var array{data: list<array<string, mixed>>, meta: array<string, int>} $body */
        $body = $this->getRaw('tickets', array_filter([
            'status' => $status,
            'reference' => $reference,
            'page' => $page,
            'per_page' => $perPage,
        ], static fn (mixed $v): bool => $v !== null));

        return TicketPage::fromResponse($body);
    }

    /**
     * Ouvre un ticket : la personne en est le demandeur.
     *
     * Passez `reference` (l'identifiant du ticket chez vous) : l'ouverture
     * devient rejouable, nanoCRM rend le ticket déjà ouvert au lieu d'un
     * doublon.
     *
     * @param  list<array{email: string, name?: string|null}>  $cc
     * @param  string|null  $priority  `low`, `normal`, `high` ou `urgent`
     */
    public function open(string $subject, string $bodyHtml, array $cc = [], ?string $priority = null, ?string $reference = null): Ticket
    {
        return Ticket::fromArray($this->send('post', 'tickets', array_filter([
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'cc' => $cc !== [] ? $cc : null,
            'priority' => $priority,
            'reference' => $reference,
        ], static fn (mixed $v): bool => $v !== null), retry: $reference !== null));
    }

    /** Le ticket, avec ses participants et ses messages publics. */
    public function ticket(string $uuid): Ticket
    {
        return Ticket::fromArray($this->get("tickets/{$uuid}"));
    }

    /** Écrit dans le fil. Rouvre un ticket en attente ou résolu. */
    public function write(string $uuid, string $bodyHtml): Message
    {
        return Message::fromArray($this->send('post', "tickets/{$uuid}/messages", ['body_html' => $bodyHtml]));
    }

    public function addCc(string $uuid, string $email, ?string $name = null): Ticket
    {
        return Ticket::fromArray($this->send('post', "tickets/{$uuid}/participants", array_filter(
            ['email' => $email, 'name' => $name],
            static fn (mixed $v): bool => $v !== null,
        )));
    }

    /** Retire une personne en copie (le demandeur ne se retire pas). */
    public function removeParticipant(string $uuid, string $participantUuid): Ticket
    {
        return Ticket::fromArray($this->send('delete', "tickets/{$uuid}/participants/{$participantUuid}"));
    }

    /** « C'est réglé. » */
    public function resolve(string $uuid): Ticket
    {
        return Ticket::fromArray($this->send('post', "tickets/{$uuid}/resolve"));
    }

    public function reopen(string $uuid): Ticket
    {
        return Ticket::fromArray($this->send('post', "tickets/{$uuid}/reopen"));
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $body = $this->getRaw($path, $query);
        /** @var array<string, mixed> $data */
        $data = $body['data'] ?? [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getRaw(string $path, array $query): array
    {
        $response = $this->request(retry: true)->get($path, $query);

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = [], bool $retry = false): array
    {
        $response = $this->request($retry)->{$method}($path, $data);
        $body = $this->decode($response);
        /** @var array<string, mixed> $payload */
        $payload = $body['data'] ?? [];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw HelpdeskException::fromResponse($response);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Une lecture, ou une ouverture avec référence, se rejoue sur une coupure
     * réseau ; une écriture simple non (elle a peut-être abouti).
     */
    private function request(bool $retry): PendingRequest
    {
        $request = $this->helpdesk->http()
            ->baseUrl($this->helpdesk->url())
            ->withToken($this->helpdesk->key())
            ->acceptJson()
            ->asJson()
            ->timeout($this->helpdesk->timeout())
            ->connectTimeout(5)
            ->withHeaders(array_filter([
                'User-Agent' => 'nanocrm-helpdesk-php/1',
                'X-Helpdesk-User' => $this->email,
                'X-Helpdesk-User-Name' => $this->name,
                'X-Helpdesk-Scope' => $this->companyWide ? 'company' : null,
            ], static fn (?string $v): bool => $v !== null && $v !== ''));

        return $retry
            ? $request->retry(3, 300, static fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
            : $request;
    }
}
