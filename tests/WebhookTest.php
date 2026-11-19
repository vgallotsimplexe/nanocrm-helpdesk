<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Tests;

use Illuminate\Support\Facades\Event;
use Nanocrm\Helpdesk\Events\HelpdeskWebhook;
use Nanocrm\Helpdesk\Events\MessageCreated;
use Nanocrm\Helpdesk\Events\TicketStatusChanged;
use Nanocrm\Helpdesk\Webhooks\WebhookSignature;

final class WebhookTest extends TestCase
{
    private function deliver(array $payload, ?string $key = null, ?int $timestamp = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/nanocrm/helpdesk/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_NANOCRM_SIGNATURE' => WebhookSignature::sign($body, $key ?? self::KEY, $timestamp),
        ], content: $body);
    }

    private static function message(string $delivery, int $revision): array
    {
        return [
            'delivery' => $delivery,
            'event' => 'message.created',
            'occurred_at' => '2026-11-19T10:05:00+00:00',
            'actor' => ['type' => 'agent', 'name' => 'Claire (support)', 'email' => null],
            'ticket' => self::ticket($revision),
            'message' => [
                'uuid' => "m{$revision}", 'from' => 'agent', 'author' => ['name' => 'Claire', 'email' => null],
                'body_html' => '<p>Réessayez</p>', 'body_text' => 'Réessayez', 'created_at' => '2026-11-19T10:05:00+00:00',
            ],
        ];
    }

    public function test_il_verifie_la_signature_et_declenche_l_evenement(): void
    {
        Event::fake([HelpdeskWebhook::class, MessageCreated::class]);

        $this->deliver(self::message('d1', 2), key: 'nhk_autre')->assertStatus(401);
        $this->deliver(self::message('d1', 2), timestamp: time() - 3600)->assertStatus(401);
        Event::assertNothingDispatched();

        $this->deliver(self::message('d1', 2))->assertNoContent();

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $e): bool {
            return $e->message?->isFromAgent() === true
                && ! $e->stale
                && $e->recipients() === ['jean@acme.fr', 'paul@acme.fr'];
        });
    }

    public function test_il_ignore_un_doublon_et_marque_un_webhook_en_retard(): void
    {
        Event::fake([MessageCreated::class, TicketStatusChanged::class]);

        $this->deliver(self::message('d3', 3))->assertNoContent();
        $this->deliver(self::message('d3', 3))->assertOk()->assertJson(['duplicate' => true]);
        // La révision 2 arrive après la 3 (rejeu après une panne).
        $this->deliver(self::message('d2', 2))->assertNoContent();

        Event::assertDispatchedTimes(MessageCreated::class, 2);
        Event::assertDispatched(MessageCreated::class, fn (MessageCreated $e): bool => $e->delivery === 'd2' && $e->stale);
    }

    public function test_l_auteur_d_un_changement_n_est_pas_prevenu(): void
    {
        Event::fake([TicketStatusChanged::class]);

        $this->deliver([
            'delivery' => 'd9',
            'event' => 'ticket.status_changed',
            'occurred_at' => '2026-11-19T10:05:00+00:00',
            'actor' => ['type' => 'participant', 'name' => 'Paul', 'email' => 'Paul@acme.fr'],
            'ticket' => self::ticket(4, ['status' => 'resolved']),
            'message' => null,
            'change' => ['from' => 'open', 'to' => 'resolved', 'reason' => 'resolved_by_client'],
        ])->assertNoContent();

        Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->to() === 'resolved'
            && $e->reason() === 'resolved_by_client'
            && $e->recipients() === ['jean@acme.fr']);
    }
}
