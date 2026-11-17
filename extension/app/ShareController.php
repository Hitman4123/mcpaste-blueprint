<?php

namespace Pterodactyl\BlueprintFramework\Extensions\mcpaste;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/**
 * The panel side of the Share log button.
 *
 * The browser sends the console lines it has. This controller checks the
 * user may open that server's console, puts the server's details on top,
 * and posts it to MCPaste with the token from the admin settings. The token
 * stays here; the browser only gets the paste link back.
 */
class ShareController extends Controller
{
    /** Lines kept from the end of the console. */
    private const MAX_LINES = 1000;

    /** Shares per user per minute, so one person can't use up the panel's MCPaste rate limit. */
    private const SHARES_PER_MINUTE = 5;

    /** Same border MCPaste reads its server-details block between. */
    private const BORDER = '--------------------------------------------------------------------';

    public function __construct(
        private McpasteSettings $settings,
        private DaemonServerRepository $daemon,
    ) {
    }

    /** GET /api/client/extensions/{identifier}/status */
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'configured' => $this->settings->token() !== null,
            'domain' => $this->settings->domain(),
        ]);
    }

    /** POST /api/client/extensions/{identifier}/servers/{uuid}/share */
    public function share(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->fail(401, 'Sign in to the panel first.');
        }

        $server = Server::query()->where(strlen($uuid) === 8 ? 'uuidShort' : 'uuid', $uuid)->first();

        // The same permission the console itself needs. A server the user
        // can't open looks the same as one that doesn't exist.
        if ($server === null || !$user->can(Permission::ACTION_WEBSOCKET_CONNECT, $server)) {
            return $this->fail(404, 'That server wasn\'t found.');
        }

        if (!$user->root_admin) {
            try {
                // Suspended, installing, restoring or transferring: the console
                // is closed to its users, so sharing it is too.
                $server->validateCurrentState();
            } catch (\Throwable) {
                return $this->fail(409, 'This server isn\'t available right now.');
            }
        }

        $request->validate([
            'lines' => 'nullable|string|max:4000000',
            'hideIps' => 'nullable|boolean',
        ]);

        $token = $this->settings->token();
        if ($token === null) {
            return $this->fail(503, 'MCPaste isn\'t set up on this panel yet. Ask an admin to add an API key under Admin → Extensions → MCPaste.');
        }

        $limiter = '{identifier}-share:' . $user->id;
        if (RateLimiter::tooManyAttempts($limiter, self::SHARES_PER_MINUTE)) {
            return $this->fail(429, 'That\'s a lot of shares. Try again in ' . RateLimiter::availableIn($limiter) . ' seconds.');
        }
        RateLimiter::hit($limiter, 60);

        $console = $this->lastLines((string) $request->input('lines', ''));
        if ($console === '') {
            return $this->fail(422, 'There\'s nothing in the console to share yet.');
        }

        $body = [
            'content' => $this->detailsBlock($server) . "\n" . $console,
            'title' => $server->name . ' — console',
            // Provenance: shown on the paste as where it came from.
            'domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost(),
        ];
        if ($request->has('hideIps')) {
            $body['hideIps'] = $request->boolean('hideIps');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)
                ->post($this->settings->domain() . '/api/create', $body);
        } catch (\Throwable $error) {
            Log::warning('MCPaste: could not reach ' . $this->settings->domain(), ['error' => $error->getMessage()]);

            return $this->fail(502, 'MCPaste didn\'t answer. Try again in a moment.');
        }

        if ($response->status() === 401) {
            return $this->fail(502, 'MCPaste refused this panel\'s API key. Ask an admin to check Admin → Extensions → MCPaste.');
        }
        if ($response->status() === 429) {
            return $this->fail(429, 'MCPaste is rate limiting this panel. Try again in a minute.');
        }

        $url = $response->json('url');
        if (!$response->successful() || !is_string($url) || !preg_match('#^https?://#i', $url)) {
            Log::warning('MCPaste: unexpected answer', ['status' => $response->status()]);

            return $this->fail(502, 'MCPaste couldn\'t save that log (HTTP ' . $response->status() . ').');
        }

        try {
            Activity::event('server:console.share')->subject($server)->property('url', $url)->log();
        } catch (\Throwable) {
            // The activity log is a nicety; the paste exists either way.
        }

        return new JsonResponse([
            'url' => $url,
            'key' => $response->json('key'),
            'expires' => $response->json('expires'),
        ], 201);
    }

    private function fail(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private function lastLines(string $raw): string
    {
        $lines = preg_split('/\r?\n/', rtrim($raw));
        if ($lines === false) {
            return '';
        }

        return trim(implode("\n", array_slice($lines, -self::MAX_LINES)), "\n");
    }

    /** The block MCPaste shows as the server's details card. */
    private function detailsBlock(Server $server): string
    {
        $server->loadMissing(['node', 'egg', 'user']);

        $details = [
            'Server' => $server->name,
            'Node' => $server->node?->name,
        ];

        try {
            $live = $this->daemon->setServer($server)->getDetails();
            $usage = $live['utilization'] ?? [];
            $details['State'] = $live['state'] ?? ($usage['state'] ?? null);
            if (isset($usage['cpu_absolute'], $usage['memory_bytes'])) {
                $details['CPU / RAM'] = $this->percent((float) $usage['cpu_absolute']) . ' · ' . $this->size((int) $usage['memory_bytes'])
                    . ($server->memory > 0 ? ' of ' . $this->size($server->memory * 1024 * 1024) : '');
            }
            if (isset($usage['disk_bytes'])) {
                $details['Disk'] = $this->size((int) $usage['disk_bytes'])
                    . ($server->disk > 0 ? ' of ' . $this->size($server->disk * 1024 * 1024) : '');
            }
        } catch (\Throwable) {
            // Wings unreachable: share the log without live numbers.
            $details['State'] = 'unknown (node not answering)';
        }

        $details['Docker Image'] = $server->image;
        $details['Egg'] = $server->egg?->name;

        if ($this->settings->shareOwnerEmail()) {
            $details['Owner'] = $server->user?->email;
        }

        $rows = [];
        foreach ($details as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // One line per field: a newline would end the block early.
            $rows[] = $label . ': ' . str_replace(["\r", "\n"], ' ', (string) $value);
        }

        return self::BORDER . "\n" . implode("\n", $rows) . "\n" . self::BORDER;
    }

    /** "4.21 GB" from 1 GB up, "312 MB" below, so small servers don't read "0.00 GB". */
    private function size(int $bytes): string
    {
        $gigabytes = $bytes / 1024 / 1024 / 1024;

        return $gigabytes >= 1
            ? number_format($gigabytes, 2) . ' GB'
            : number_format($bytes / 1024 / 1024, 0) . ' MB';
    }

    /** "187%", or "0.4%" below 10, so an idle server doesn't read "0%". */
    private function percent(float $value): string
    {
        return ($value < 10 ? number_format($value, 1) : number_format($value, 0)) . '%';
    }
}
