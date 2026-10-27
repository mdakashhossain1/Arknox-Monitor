<?php

namespace Modules\ArknoxMonitor\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ArknoxMonitor\App\Services\CounterBuffer;
use Symfony\Component\HttpFoundation\Response;

class FlushBufferedCounters
{
    public function handle(Request $request, Closure $next): Response
    {
        CounterBuffer::flush();

        return $next($request);
    }
}
