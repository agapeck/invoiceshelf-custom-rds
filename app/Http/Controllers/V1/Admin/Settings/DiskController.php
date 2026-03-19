<?php

namespace App\Http\Controllers\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiskEnvironmentRequest;
use App\Http\Resources\FileDiskResource;
use App\Models\FileDisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiskController extends Controller
{
    /**
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('manage file disk');

        $limit = $request->has('limit') ? $request->limit : 5;
        $companyId = (int) $request->header('company');
        $disks = FileDisk::query()
            ->forCompanyContext($companyId)
            ->applyFilters($request->all())
            ->latest()
            ->paginateData($limit);

        return FileDiskResource::collection($disks);
    }

    /**
     * @return JsonResponse
     */
    public function store(DiskEnvironmentRequest $request)
    {
        $this->authorize('manage file disk');

        if (! FileDisk::validateCredentials($request->credentials, $request->driver)) {
            return respondJson('invalid_credentials', 'Invalid Credentials.');
        }

        $disk = FileDisk::createDisk($request);

        return new FileDiskResource($disk);
    }

    /**
     * @param  \App\Models\FileDisk  $file_disk
     * @return JsonResponse
     */
    public function update(FileDisk $disk, Request $request)
    {
        $this->authorize('manage file disk');
        $this->ensureDiskIsAccessible($disk, (int) $request->header('company'));

        $credentials = $request->credentials;
        $driver = $request->driver;

        if ($credentials && $driver && $disk->type !== 'SYSTEM') {
            if (! FileDisk::validateCredentials($credentials, $driver)) {
                return respondJson('invalid_credentials', 'Invalid Credentials.');
            }

            $disk->updateDisk($request);
        } elseif ($request->set_as_default) {
            $disk->setAsDefaultDisk();
        }

        return new FileDiskResource($disk);
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function show($disk)
    {
        $this->authorize('manage file disk');

        $diskData = [];
        switch ($disk) {
            case 'local':
                $diskData = [
                    'root' => config('filesystems.disks.local.root'),
                ];

                break;

            case 's3':
                $diskData = [
                    'key' => '',
                    'secret' => '',
                    'region' => '',
                    'bucket' => '',
                    'root' => '',
                ];

                break;

            case 's3compat':
                $diskData = [
                    'endpoint' => '',
                    'key' => '',
                    'secret' => '',
                    'region' => '',
                    'bucket' => '',
                    'root' => '',
                ];
                break;

            case 'doSpaces':
                $diskData = [
                    'key' => '',
                    'secret' => '',
                    'region' => '',
                    'bucket' => '',
                    'endpoint' => '',
                    'root' => '',
                ];

                break;

            case 'r2':
                $diskData = [
                    'endpoint' => '',
                    'key' => '',
                    'secret' => '',
                    'region' => 'auto',
                    'bucket' => '',
                    'root' => '',
                ];
                break;

            case 'dropbox':
                $diskData = [
                    'token' => '',
                    'key' => '',
                    'secret' => '',
                    'app' => '',
                    'root' => '',
                ];

                break;
        }

        $data = array_merge($diskData);

        return response()->json($data);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\FileDisk  $taxType
     * @return \Illuminate\Http\Response
     */
    public function destroy(FileDisk $disk)
    {
        $this->authorize('manage file disk');
        $this->ensureDiskIsAccessible($disk, (int) request()->header('company'));

        if ($disk->setAsDefault() && $disk->type === 'SYSTEM') {
            return respondJson('not_allowed', 'Not Allowed');
        }

        $disk->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function ensureDiskIsAccessible(FileDisk $disk, int $companyId): void
    {
        if ($disk->company_id !== null && (int) $disk->company_id !== $companyId) {
            abort(403, 'Invalid file disk context.');
        }
    }

    /**
     * @return JsonResponse
     */
    public function getDiskDrivers()
    {
        $this->authorize('manage file disk');

        $drivers = [
            [
                'name' => 'Local',
                'value' => 'local',
            ],
            [
                'name' => 'Amazon S3',
                'value' => 's3',
            ],
            [
                'name' => 'S3 Compatible Storage',
                'value' => 's3compat',
            ],
            [
                'name' => 'Digital Ocean Spaces',
                'value' => 'doSpaces',
            ],
            [
                'name' => 'Cloudflare R2',
                'value' => 'r2',
            ],
            [
                'name' => 'Dropbox',
                'value' => 'dropbox',
            ],
        ];

        $default = config('filesystems.default');

        return response()->json([
            'drivers' => $drivers,
            'default' => $default,
        ]);
    }
}
