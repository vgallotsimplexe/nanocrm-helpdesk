<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Webhooks;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nanocrm\Helpdesk\Data\Actor;
use Nanocrm\Helpdesk\Data\Message;
use Nanocrm\Helpdesk\Data\Ticket;
use Nanocrm\Helpdesk\Events\HelpdeskEvent;
use Nanocrm\Helpdesk\Events\MessageCreated;
use Nanocrm\Helpdesk\Events\MessageDeleted;
use Nanocrm\Helpdesk\Events\MessageUpdated;
use Nanocrm\Helpdesk\Events\ParticipantsChanged;
use Nanocrm\Helpdesk\Events\TicketOpened;
use Nanocrm\Helpdesk\Events\TicketStatusChanged;
use Nanocrm\Helpdesk\Events\TicketUpdated;
use Nanocrm\Helpdesk\Events\UnknownEvent;
use Nanocrm\Helpdesk\Exceptions\InvalidSignatureException;
use Nanocrm\Helpdesk\Helpdesk;

/**
 * Reçoit les webhooks de nanoCRM.
 *
 * 1. Vérifie la signature avec la clé de la société courante (le middleware
 *    de l'outil qui choisit la société passe avant) : 401 sinon.
 * 2. Ignore un webhook déjà reçu (`delivery`) : nanoCRM peut en renvoyer un.
 * 3. Marque `stale` un webhook plus ancien que le dernier reçu pour ce ticket.
 * 4. Déclenche l'événement Laravel. Si un écouteur échoue, la réponse est une
 *    erreur et nanoCRM renverra le webhook plus tard.
 */
final class WebhookController
{
    private const EVENTS = [
        'ticket.opened' => TicketOpened::class,
        'ticket.updated' => TicketUpdated::class,
        'ticket.status_changed' => TicketStatusChanged::class,
        'ticket.participants_changed' => ParticipantsChanged::class,
        'message.created' => MessageCreated::class,
        'message.updated' => MessageUpdated::class,
        'message.deleted' => MessageDeleted::class,
    ];

    public function __construct(
        private readonly Helpdesk $helpdesk,
        private readonly Config $config,
        private readonly CacheFactory $caches,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $key = $this->helpdesk->key();

        try {
            WebhookSignature::verify(
                $request->header('X-Nanocrm-Signature'),
                $body,
                $key,
                (int) $this->config->get('helpdesk.webhook.tolerance', 300),
            );
        } catch (InvalidSignatureException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ! isset($payload['delivery'], $payload['event'], $payload['ticket']) || ! is_array($payload['ticket'])) {
            return new JsonResponse(['message' => 'Webhook mal formé.'], 422);
        }

        // Les clés de cache portent l'empreinte de la clé : deux sociétés de
        // l'outil ne se mélangent pas, même dans un cache partagé.
        $scope = substr(hash('sha256', $key), 0, 16);
        $cache = $this->cache();
        $deliveryKey = "nanocrm-helpdesk:{$scope}:delivery:{$payload['delivery']}";

        if ($cache->has($deliveryKey)) {
            return new JsonResponse(['duplicate' => true]);
        }

        $ticket = Ticket::fromArray($payload['ticket']);
        $revisionKey = "nanocrm-helpdesk:{$scope}:revision:{$ticket->uuid}";
        $known = (int) $cache->get($revisionKey, 0);
        $stale = $ticket->revision <= $known;

        $this->events->dispatch($this->event($payload, $ticket, $stale));

        $ttl = (int) $this->config->get('helpdesk.webhook.remember_for', 604800);
        $cache->put($deliveryKey, true, $ttl);

        if (! $stale) {
            $cache->put($revisionKey, $ticket->revision, $ttl);
        }

        return new JsonResponse(status: 204);
    }

    /** @param  array<string, mixed>  $payload */
    private function event(array $payload, Ticket $ticket, bool $stale): HelpdeskEvent
    {
        $class = self::EVENTS[(string) $payload['event']] ?? UnknownEvent::class;
        /** @var array<string, mixed>|null $message */
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        /** @var array<string, mixed>|null $actor */
        $actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : null;

        return new $class(
            (string) $payload['event'],
            (string) $payload['delivery'],
            $ticket,
            $message !== null ? Message::fromArray($message) : null,
            Actor::fromArray($actor),
            new DateTimeImmutable((string) ($payload['occurred_at'] ?? 'now')),
            $stale,
            $payload,
        );
    }

    private function cache(): Cache
    {
        $store = $this->config->get('helpdesk.webhook.cache_store');

        return $this->caches->store(is_string($store) ? $store : null);
    }
}
