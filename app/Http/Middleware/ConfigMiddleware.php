<?php

namespace App\Http\Middleware;

use App\Models\FileDisk;
use App\Space\InstallUtils;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
                $fileDiskQuery = FileDisk::query()->whereKey($request->file_disk_id);
                $file_disk = null;

                if ($request->hasHeader('company') && Schema::hasColumn('file_disks', 'company_id')) {
                    $companyId = (int) $request->header('company');

                    $file_disk = (clone $fileDiskQuery)
                        ->where(function ($query) use ($companyId) {
                            $query->where('company_id', $companyId)
                                ->orWhereNull('company_id');
                        })
                        ->orderByRaw('company_id IS NULL')
                        ->first();
                }

                if (! $file_disk) {
                    $file_disk = $fileDiskQuery->first();
                }

                if ($file_disk) {
                    $file_disk->setConfig();
                }
            }
            // Default file disk is now handled by AppConfigProvider during boot
        }

        return $next($request);
    }
}
