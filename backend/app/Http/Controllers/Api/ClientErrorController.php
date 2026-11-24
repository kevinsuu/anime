<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives JavaScript errors from visitors' browsers so production problems
 * show up in a log instead of only on someone's screen. Reports are
 * anonymous (no user id or IP is stored) and rate limited per client.
 */
final class ClientErrorController extends Controller
{
    private const USER_AGENT_MAX_LENGTH = 300;

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:error,unhandledrejection,vue'],
            'message' => ['required', 'string', 'max:500'],
            'page' => ['required', 'string', 'max:300'],
            'source' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer', 'min:0'],
            'column' => ['nullable', 'integer', 'min:0'],
            'stack' => ['nullable', 'string', 'max:4000'],
            'release' => ['nullable', 'string', 'max:40'],
        ]);

        Log::channel('client')->warning('client error', [
            'kind' => $validated['kind'],
            'message' => $validated['message'],
            'page' => $validated['page'],
            'source' => $validated['source'] ?? null,
            'line' => $validated['line'] ?? null,
            'column' => $validated['column'] ?? null,
            'stack' => $validated['stack'] ?? null,
            'release' => $validated['release'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), self::USER_AGENT_MAX_LENGTH, ''),
        ]);

        return response()->noContent();
    }
}
