<?php

namespace Pterodactyl\BlueprintFramework\Extensions\mcpaste;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\BlueprintBaseLibrary;

/**
 * The extension's settings, kept in the panel's `settings` table through
 * Blueprint's database helpers.
 *
 *   domain       where pastes go, e.g. https://mcpaste.com (any host)
 *   token        the MCPaste API key, encrypted with the panel's APP_KEY
 *   owner_email  "1" to put the server owner's email in the details block
 *
 * The token is only ever read here, server-side. Nothing returns it to a
 * browser: the admin page shows its first and last characters, and the
 * console button only gets back the paste link.
 */
class McpasteSettings
{
    public const DEFAULT_DOMAIN = 'https://mcpaste.com';

    private const TABLE = '{identifier}';

    public function __construct(private BlueprintBaseLibrary $blueprint)
    {
    }

    public function domain(): string
    {
        $value = $this->blueprint->dbGet(self::TABLE, 'domain');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_DOMAIN;
    }

    public function token(): ?string
    {
        $value = $this->blueprint->dbGet(self::TABLE, 'token');
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY changed since it was saved: treat it as unset.
            return null;
        }
    }

    /** "mcp_live_9f3a…b41c", or null when no token is set. */
    public function tokenHint(): ?string
    {
        $token = $this->token();

        return $token === null ? null : substr($token, 0, 13) . '…' . substr($token, -4);
    }

    public function shareOwnerEmail(): bool
    {
        return $this->blueprint->dbGet(self::TABLE, 'owner_email') === '1';
    }

    public function setDomain(string $domain): void
    {
        $this->blueprint->dbSet(self::TABLE, 'domain', $domain);
    }

    public function setToken(?string $token): void
    {
        $this->blueprint->dbSet(self::TABLE, 'token', $token === null ? '' : Crypt::encryptString($token));
    }

    public function setShareOwnerEmail(bool $share): void
    {
        $this->blueprint->dbSet(self::TABLE, 'owner_email', $share ? '1' : '0');
    }

    /**
     * "mcpaste.com" → "https://mcpaste.com", trailing slash dropped. Any host
     * is allowed (a host may run its own MCPaste or a white-label domain);
     * only http(s) URLs with a host and no query are accepted.
     */
    public static function normaliseDomain(string $input): ?string
    {
        $value = trim($input);
        if ($value === '') {
            return self::DEFAULT_DOMAIN;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);
        if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');

        return $origin . $path;
    }

    public static function looksLikeToken(string $token): bool
    {
        return (bool) preg_match('/^mcp_live_[a-f0-9]{40}$/', $token);
    }
}
