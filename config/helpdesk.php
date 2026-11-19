<?php

declare(strict_types=1);

/*
| Liaison avec le support de nanoCRM.
|
| Les deux valeurs viennent de nanoCRM : fiche du client, onglet « Support »,
| « Relier un outil ». La clé ne s'y affiche qu'une fois.
|
| Outil multi-société : chaque société a sa propre clé. Remplacez la valeur
| à chaque requête (un middleware après la résolution de la société), ou
| donnez une fonction à `Helpdesk::resolveKeyUsing()`. Le paquet relit la
| configuration à chaque appel, jamais à la construction.
*/

return [
    'url' => env('NANOCRM_HELPDESK_URL'),

    'key' => env('NANOCRM_HELPDESK_KEY'),

    'timeout' => (int) env('NANOCRM_HELPDESK_TIMEOUT', 15),

    'webhook' => [
        // Adresse à déclarer dans nanoCRM. null : pas de route, vous montez
        // vous-même `Nanocrm\Helpdesk\Webhooks\WebhookController`.
        'path' => env('NANOCRM_HELPDESK_WEBHOOK_PATH', 'nanocrm/helpdesk/webhook'),

        // Ajoutez le middleware qui choisit la société avant la vérification :
        // la signature se contrôle avec la clé de cette société.
        'middleware' => ['api'],

        // Écart toléré entre l'horodatage signé et l'heure locale, en secondes.
        'tolerance' => 300,

        // Durée pendant laquelle un webhook déjà reçu est reconnu (dédoublonnage).
        'remember_for' => 60 * 60 * 24 * 7,

        // Magasin de cache pour le dédoublonnage et les révisions (null : défaut).
        'cache_store' => null,
    ],
];
