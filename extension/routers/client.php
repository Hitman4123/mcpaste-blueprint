<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\mcpaste\ShareController;

// Prefixed by Blueprint with /api/client/extensions/{identifier}, behind the
// panel's client-API middleware: a signed-in user (session) or client API key.
//
// The namespace is written out, not {appcontext}: Blueprint beta-2026-08
// drops the backslashes when it fills that placeholder in.

// Whether the button has anything to post with, and where to.
Route::get('/status', [ShareController::class, 'status']);

// Shares the console of the server with this UUID (or 8-character short
// id). ShareController checks the user may open that server's console
// before anything leaves the panel.
Route::post('/servers/{uuid}/share', [ShareController::class, 'share'])
    ->where('uuid', '[0-9a-zA-Z-]{8,36}');
