<?php

namespace App\Http\Middleware;

use App\Models\FileDisk;
use App\Space\InstallUtils;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallUtils::isDbCreated()) {
            // Only handle dynamic file disk switching when file_disk_id is provided
            if ($request->has('file_disk_id')) {
                $companyId = $request->hasHeader('company') ? (int) $request->header('company') : null;
                $file_disk = FileDisk::query()
                    ->forCompanyContext($companyId)
                    ->whereKey($request->file_disk_id)
                    ->first();

                if ($file_disk) {
                    $file_disk->setConfig();
                } else {
                    return response()->json(['error' => 'invalid_file_disk_context'], 403);
                }
            }
            // Default file disk is now handled by AppConfigProvider during boot
        }

        return $next($request);
    }
}
