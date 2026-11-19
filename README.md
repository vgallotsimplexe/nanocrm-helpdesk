# nanocrm/helpdesk

Client Laravel du support de [nanoCRM](https://nanocrm.fr). Une application reliée au support d'un client de nanoCRM s'en sert pour :

- ouvrir et suivre des tickets au nom de ses utilisateurs ;
- recevoir les réponses du support.

## Principe

- **Une application = un client de nanoCRM.** Pour une application multi-société, chaque société de l'application est un client dans nanoCRM, avec sa propre connexion et sa propre clé.
- **Chaque appel se fait au nom d'une personne**, identifiée par son email.
  - La clé atteste que l'appel vient de l'application.
  - L'application atteste qui est la personne.
  - nanoCRM décide de ce qu'elle voit : les tickets dont elle est le demandeur ou où elle est en copie. En `companyWide()`, elle voit tous les tickets de l'application, mais seulement si l'application lui ouvre cette vue.
- **nanoCRM fait foi.**
  - Les webhooks préviennent l'application de chaque changement.
  - L'API lit l'état exact.
  - Les notes internes du support ne sortent jamais de nanoCRM.

## Installation

```bash
composer require nanocrm/helpdesk
```

PHP 8.3 ou plus, Laravel 11, 12 ou 13. Le service provider et la façade `Helpdesk` sont découverts automatiquement.

Dans nanoCRM, ouvrez la fiche du client, onglet **Support**, puis **Outils connectés › Relier un outil**. Indiquez l'adresse du webhook de l'application, par défaut `https://<hôte de l'application>/nanocrm/helpdesk/webhook`. La clé ne s'affiche qu'une fois. Recopiez-la tout de suite dans l'application :

```dotenv
NANOCRM_HELPDESK_URL=https://<votre-société>.nanocrm.fr/support/v1
NANOCRM_HELPDESK_KEY=nhk_…
```

La même clé authentifie les appels et signe les webhooks.

### Application multi-société

La clé dépend de la société courante. Le paquet relit la configuration **à chaque appel**. Il suffit donc que la bonne valeur soit en place au moment de l'appel. Deux façons de faire :

- un middleware, placé après la résolution de la société, qui écrit la clé dans la configuration :

  ```php
  config(['helpdesk.key' => tenant()->nanocrm_helpdesk_key]);
  ```

- ou une fonction de résolution, déclarée une fois, par exemple dans un service provider :

  ```php
  Helpdesk::resolveKeyUsing(fn () => tenant()?->nanocrm_helpdesk_key);
  ```

Pour les webhooks, ajoutez ce middleware de société dans `helpdesk.webhook.middleware` (`php artisan vendor:publish --tag=helpdesk-config`). La signature est vérifiée avec la clé de la société qui reçoit le webhook.

Une société sans clé n'a pas de support : `Helpdesk::isConfigured()` permet de masquer le menu.

## Utilisation

```php
use Nanocrm\Helpdesk\Facades\Helpdesk;

$moi = Helpdesk::as($user->email, $user->name);

// Ouvrir un ticket. La référence rend l'appel rejouable sans doublon.
$ticket = $moi->open(
    subject: 'Export PDF bloqué',
    bodyHtml: Helpdesk::textToHtml($request->input('message')),
    cc: [['email' => 'paul@acme.fr', 'name' => 'Paul']],
    priority: 'high',            // low, normal (par défaut), high, urgent
    reference: (string) $local->id,
);

$moi->tickets();                 // les miens, à traiter (ouverts ou en attente)
$moi->tickets(status: null);     // tous les miens
$moi->ticket($uuid);             // le ticket, ses participants et ses messages publics
$moi->write($uuid, $html);       // écrire dans le fil (rouvre un ticket en attente ou résolu)
$moi->addCc($uuid, 'marie@acme.fr', 'Marie');
$moi->removeParticipant($uuid, $participantUuid);   // le demandeur ne se retire pas
$moi->resolve($uuid);            // « c'est réglé »
$moi->reopen($uuid);

// Administrateurs de l'application : tous les tickets de la société.
// Écrire sur un ticket où l'on n'était pas y met en copie.
Helpdesk::as($admin->email)->companyWide()->tickets();

// Écran d'administration : vérifier l'adresse et la clé.
Helpdesk::check();               // ConnectionInfo, ou UnauthenticatedException
```

Une personne de l'application ne peut pas fermer un ticket : la fermeture est définitive et c'est le support qui la décide.

### Erreurs

Toutes les erreurs dérivent de `HelpdeskException`, qui porte :

- `errorCode` : le code stable de nanoCRM (`ticket.closed`, `ticket.requester_not_removable`…) ;
- `status` : le statut HTTP ;
- `errors` : les erreurs de validation, champ par champ.

Exceptions spécialisées :

- `UnauthenticatedException` : clé absente, inconnue ou révoquée.
- `TicketNotFoundException` : ticket inexistant, ou que cette personne ne voit pas.
- `ValidationException` : données refusées.
- `ConfigurationException` : adresse ou clé manquante.

## Webhooks

Le paquet enregistre la route `POST /nanocrm/helpdesk/webhook` (réglable, ou `null` pour la monter vous-même). Pour chaque webhook, il :

1. vérifie la signature (`X-Nanocrm-Signature: t=…,v1=…`, HMAC-SHA256) et son âge (5 minutes de tolérance) ;
2. ignore un webhook déjà reçu (même `delivery`) ;
3. marque `stale` un webhook plus ancien que le dernier reçu pour ce ticket ;
4. déclenche un événement Laravel.

Si un écouteur échoue, la réponse est une erreur et nanoCRM renverra le webhook plus tard (jusqu'à une journée).

Événements, tous dans `Nanocrm\Helpdesk\Events` :

| Événement | Quand |
|---|---|
| `TicketOpened` | Ticket ouvert, depuis l'application ou par le support. Porte le premier message |
| `MessageCreated` | Message public : réponse du support (`$e->message->isFromAgent()`) ou message d'une personne de l'application |
| `MessageUpdated`, `MessageDeleted` | Message corrigé ou retiré par le support |
| `TicketStatusChanged` | Changement de statut (`from()`, `to()`, `reason()`) |
| `TicketUpdated` | Sujet ou priorité modifié |
| `ParticipantsChanged` | Personne mise en copie ou retirée |
| `UnknownEvent` | Événement que cette version du paquet ne connaît pas |

Tous implémentent `HelpdeskWebhook`, qui permet de tout écouter d'un coup :

```php
Event::listen(HelpdeskWebhook::class, function (HelpdeskEvent $e) {
    if (! $e->stale) {
        LocalTicket::syncFrom($e->ticket);          // état du ticket, révision comprise
    }

    if ($e instanceof MessageCreated) {
        Notification::route('mail', $e->recipients())   // participants, sauf l'auteur
            ->notify(new TicketReplied($e->ticket, $e->message));
    }
});
```

Les changements faits depuis l'application sont eux aussi notifiés. L'application prévient ainsi les autres participants, et `recipients()` écarte la personne qui a fait le changement.

Le dédoublonnage et les révisions sont gardés en cache (`helpdesk.webhook.cache_store`). Dans une application multi-société, les clés de cache sont distinctes par société.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Licence

MIT, voir [LICENSE](LICENSE).
