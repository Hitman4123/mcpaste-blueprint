<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\{identifier};

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\mcpaste\McpasteSettings;

/**
 * Admin → Extensions → MCPaste: where pastes go, the API key they're sent
 * with, and whether the owner's email rides along.
 */
class {identifier}ExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
        private AlertsMessageBag $alert,
        private McpasteSettings $settings,
    ) {
    }

    public function index(): View
    {
        return $this->view->make('admin.extensions.{identifier}.index', [
            'root' => '/admin/extensions/{identifier}',
            'blueprint' => $this->blueprint,
            'domain' => $this->settings->domain(),
            'defaultDomain' => McpasteSettings::DEFAULT_DOMAIN,
            // Never the token itself: its first and last characters only.
            'tokenHint' => $this->settings->tokenHint(),
            'ownerEmail' => $this->settings->shareOwnerEmail(),
        ]);
    }

    /** PATCH /admin/extensions/{identifier} */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'domain' => 'nullable|string|max:255',
            'token' => 'nullable|string|max:255',
            'clear_token' => 'nullable|in:1',
            'owner_email' => 'nullable|in:1',
        ]);

        $domain = McpasteSettings::normaliseDomain((string) $request->input('domain', ''));
        if ($domain === null) {
            $this->alert->danger('That MCPaste address isn\'t a web address. Use something like https://mcpaste.com.')->flash();

            return redirect()->route('admin.extensions.{identifier}.index');
        }

        $token = trim((string) $request->input('token', ''));
        if ($token !== '' && !McpasteSettings::looksLikeToken($token)) {
            $this->alert->danger('That doesn\'t look like an MCPaste API key. They start with mcp_live_ and come from the API keys page on MCPaste.')->flash();

            return redirect()->route('admin.extensions.{identifier}.index');
        }

        $this->settings->setDomain($domain);
        $this->settings->setShareOwnerEmail($request->input('owner_email') === '1');
        if ($request->input('clear_token') === '1') {
            $this->settings->setToken(null);
        } elseif ($token !== '') {
            // Left empty, the saved token is kept.
            $this->settings->setToken($token);
        }

        $this->checkConnection();

        return redirect()->route('admin.extensions.{identifier}.index');
    }

    /** Asks MCPaste who the saved token belongs to, and says so. */
    private function checkConnection(): void
    {
        $token = $this->settings->token();
        if ($token === null) {
            $this->alert->warning('Saved. Add an MCPaste API key before the Share log button can post anything.')->flash();

            return;
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)
                ->get($this->settings->domain() . '/api/whoami');
        } catch (\Throwable $error) {
            $this->alert->warning('Saved, but ' . $this->settings->domain() . ' didn\'t answer. Check the address.')->flash();

            return;
        }

        if ($response->status() === 401) {
            $this->alert->danger('Saved, but MCPaste refused that API key. Make a new one on the API keys page and paste it here.')->flash();
        } elseif (!$response->successful()) {
            $this->alert->warning('Saved, but MCPaste answered with HTTP ' . $response->status() . '.')->flash();
        } else {
            $key = $response->json('key.label');
            $this->alert->success('Saved. Connected to MCPaste' . (is_string($key) ? ' with the key "' . e($key) . '"' : '') . '.')->flash();
        }
    }
}
