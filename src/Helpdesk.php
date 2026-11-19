<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Nanocrm\Helpdesk\Data\ConnectionInfo;
use Nanocrm\Helpdesk\Exceptions\ConfigurationException;

/**
 * Point d'entrée du paquet (façade `Helpdesk`).
 *
 * Tout appel se fait **au nom d'une personne** de l'outil, par son email :
 *
 *     Helpdesk::as($user->email, $user->name)->open('Export bloqué', $html);
 *     Helpdesk::as($user->email)->tickets();
 *     Helpdesk::as($admin->email)->companyWide()->tickets();   // administrateurs de l'outil
 *
 * nanoCRM décide de ce que la personne voit : ses tickets (demandeur ou en
 * copie), ou, en `companyWide()`, tous ceux de l'outil.
 *
 * L'adresse et la clé sont relues à chaque appel (`config('helpdesk.*')`, ou
 * `resolveKeyUsing()`) : un outil multi-société les change par société.
 */
final class Helpdesk
{
    private ?Closure $keyResolver = null;

    private ?Closure $urlResolver = null;

    public function __construct(
        private readonly Repository $config,
        private readonly Factory $http,
    ) {}

    /**
     * Donne la clé de la société courante, si elle n'est pas dans la
     * configuration (par exemple lue dans la base de la société).
     *
     * @param  (Closure(): ?string)|null  $resolver
     */
    public function resolveKeyUsing(?Closure $resolver): void
    {
        $this->keyResolver = $resolver;
    }

    /** @param  (Closure(): ?string)|null  $resolver */
    public function resolveUrlUsing(?Closure $resolver): void
    {
        $this->urlResolver = $resolver;
    }

    /** Agit au nom de cette personne de l'outil. */
    public function as(string $email, ?string $name = null): HelpdeskClient
    {
        return new HelpdeskClient($this, $email, $name);
    }

    /**
     * Vérifie l'adresse et la clé, par exemple depuis l'écran d'administration
     * de l'outil. Lève UnauthenticatedException si la clé est refusée.
     */
    public function check(): ConnectionInfo
    {
        /** @var array{label: string, company: string, webhook_url: string|null} $data */
        $data = $this->as('verification@nanocrm.invalid')->get('connection');

        return new ConnectionInfo($data['label'], $data['company'], $data['webhook_url']);
    }

    /** La clé de la société courante. */
    public function key(): string
    {
        $key = $this->keyResolver !== null ? ($this->keyResolver)() : $this->config->get('helpdesk.key');

        if (! is_string($key) || $key === '') {
            throw new ConfigurationException('NANOCRM_HELPDESK_KEY n’est pas renseignée pour cette société.');
        }

        return $key;
    }

    /** Une clé est-elle disponible ? Pour masquer le support dans une société non reliée. */
    public function isConfigured(): bool
    {
        try {
            $this->key();
            $this->url();

            return true;
        } catch (ConfigurationException) {
            return false;
        }
    }

    public function url(): string
    {
        $url = $this->urlResolver !== null ? ($this->urlResolver)() : $this->config->get('helpdesk.url');

        if (! is_string($url) || $url === '') {
            throw new ConfigurationException('NANOCRM_HELPDESK_URL n’est pas renseignée.');
        }

        return rtrim($url, '/');
    }

    public function timeout(): int
    {
        return (int) $this->config->get('helpdesk.timeout', 15);
    }

    public function http(): Factory
    {
        return $this->http;
    }

    /** Un texte saisi sans mise en forme, prêt pour `body_html`. */
    public static function textToHtml(string $text): string
    {
        $paragraphs = preg_split("/\R{2,}/u", trim($text)) ?: [];

        return implode('', array_map(
            static fn (string $p): string => '<p>'.preg_replace('/\R/u', '<br>', htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>',
            $paragraphs,
        ));
    }
}
