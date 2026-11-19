<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nanocrm\Helpdesk\Exceptions\ConfigurationException;
use Nanocrm\Helpdesk\Exceptions\HelpdeskException;
use Nanocrm\Helpdesk\Exceptions\UnauthenticatedException;
use Nanocrm\Helpdesk\Exceptions\ValidationException;
use Nanocrm\Helpdesk\Facades\Helpdesk;

final class ClientTest extends TestCase
{
    public function test_il_ouvre_un_ticket_au_nom_d_une_personne(): void
    {
        Http::fake(['*/tickets' => Http::response(['data' => self::ticket(1, [
            'messages' => [[
                'uuid' => 'm1', 'from' => 'client', 'author' => ['name' => 'Jean', 'email' => 'jean@acme.fr'],
                'body_html' => '<p>Bloqué</p>', 'body_text' => 'Bloqué', 'created_at' => '2026-11-19T10:00:00+00:00',
            ]],
        ])], 201)]);

        $ticket = Helpdesk::as('jean@acme.fr', 'Jean')->open('Export bloqué', '<p>Bloqué</p>', cc: [['email' => 'paul@acme.fr']], reference: 'LFT-42');

        $this->assertSame('SU2026-0001', $ticket->number);
        $this->assertSame('jean@acme.fr', $ticket->requester()?->email);
        $this->assertSame('Bloqué', $ticket->messages[0]->bodyText);

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://acme.nanocrm.test/support/v1/tickets'
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r->hasHeader('X-Helpdesk-User', 'jean@acme.fr')
            && $r->hasHeader('X-Helpdesk-User-Name', 'Jean')
            && ! $r->hasHeader('X-Helpdesk-Scope')
            && $r['reference'] === 'LFT-42'
            && $r['cc'] === [['email' => 'paul@acme.fr']]);
    }

    public function test_la_vue_de_la_societe_et_les_filtres_passent_dans_la_requete(): void
    {
        Http::fake(['*' => Http::response(['data' => [self::ticket()], 'meta' => ['current_page' => 1, 'last_page' => 2, 'per_page' => 25, 'total' => 30]])]);

        $page = Helpdesk::as('admin@acme.fr')->companyWide()->tickets(status: null);

        $this->assertCount(1, $page->tickets);
        $this->assertTrue($page->hasMore());
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Helpdesk-Scope', 'company') && ! str_contains($r->url(), 'status='));
    }

    public function test_la_cle_se_relit_a_chaque_appel(): void
    {
        Http::fake(['*' => Http::response(['data' => ['label' => 'x', 'company' => 'Acme', 'webhook_url' => null]])]);

        config(['helpdesk.key' => 'nhk_societe_a']);
        Helpdesk::check();
        Helpdesk::resolveKeyUsing(fn (): string => 'nhk_societe_b');
        Helpdesk::check();
        Helpdesk::resolveKeyUsing(null);

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Authorization')[0])->all();
        $this->assertSame(['Bearer nhk_societe_a', 'Bearer nhk_societe_b'], $sent);
    }

    public function test_les_refus_de_nanocrm_deviennent_des_exceptions_typees(): void
    {
        Http::fake([
            '*/tickets/a/resolve' => Http::response(['title' => 'Ce ticket est fermé', 'code' => 'ticket.closed', 'status' => 409], 409),
            '*/tickets/b/messages' => Http::response(['message' => 'invalide', 'errors' => ['body_html' => ['Le message est vide.']]], 422),
            '*/connection' => Http::response(['title' => 'Clé refusée', 'code' => 'helpdesk.unauthenticated'], 401),
        ]);

        try {
            Helpdesk::as('jean@acme.fr')->resolve('a');
            $this->fail('Refus attendu');
        } catch (HelpdeskException $e) {
            $this->assertSame('ticket.closed', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        try {
            Helpdesk::as('jean@acme.fr')->write('b', '<p></p>');
            $this->fail('Refus attendu');
        } catch (ValidationException $e) {
            $this->assertSame(['Le message est vide.'], $e->errors['body_html']);
        }

        $this->expectException(UnauthenticatedException::class);
        Helpdesk::check();
    }

    public function test_sans_cle_rien_ne_part(): void
    {
        config(['helpdesk.key' => null]);
        Http::fake();

        $this->assertFalse(Helpdesk::isConfigured());
        $this->expectException(ConfigurationException::class);
        Helpdesk::as('jean@acme.fr')->tickets();
    }

    public function test_un_texte_brut_devient_du_html_sur(): void
    {
        $this->assertSame('<p>a &lt;b&gt;<br>c</p><p>d</p>', Helpdesk::textToHtml("a <b>\nc\n\nd"));
    }
}
