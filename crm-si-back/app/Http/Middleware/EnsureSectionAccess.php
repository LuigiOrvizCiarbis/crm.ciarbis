<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSectionAccess
{
    /**
     * Require the section permission as well as the resource-level policy used
     * by the controller. Using can() preserves the tenant Owner gate bypass.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $section): Response
    {
        abort_unless($request->user()?->can("sections.{$section}"), 403);

        return $next($request);
    }
}
