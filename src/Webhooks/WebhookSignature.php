<?php

declare(strict_types=1);

namespace Nanocrm\Helpdesk\Webhooks;

use Nanocrm\Helpdesk\Exceptions\InvalidSignatureException;

/**
 * Signature des webhooks de nanoCRM :
 *
 *     X-Nanocrm-Signature: t=<horodatage unix>,v1=<HMAC-SHA256 hex>
 *
 * Le HMAC porte sur « horodatage.corps brut », avec la clé de la connexion
 * (NANOCRM_HELPDESK_KEY). L'horodatage borne le rejeu d'un envoi intercepté.
 */
final class WebhookSignature
{
    public static function sign(string $body, string $key, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $key);
    }

    public static function verify(?string $header, string $body, string $key, int $tolerance = 300, ?int $now = null): void
    {
        if ($header === null || $header === '') {
            throw new InvalidSignatureException('Signature absente.');
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$name] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            throw new InvalidSignatureException('Signature mal formée.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new InvalidSignatureException('Signature trop ancienne.');
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $key), $signature)) {
            throw new InvalidSignatureException('Signature invalide.');
        }
    }
}
