<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LivewireUpdateGetFallbackController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Log::warning('Livewire update endpoint reached with GET; redirecting back', [
            'referer' => $request->headers->get('referer'),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return redirect()->to(
            $request->headers->get('referer')
                ?: route('filament.admin.resources.new-yachts.index')
        );
    }
}
