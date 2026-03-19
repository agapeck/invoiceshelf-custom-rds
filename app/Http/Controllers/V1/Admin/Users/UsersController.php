<?php

namespace App\Http\Controllers\V1\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $limit = $request->has('limit') ? $request->limit : 10;

        $user = $request->user();
        $companyId = (int) $request->header('company');

        $users = User::applyFilters($request->all())
            ->whereHas('companies', function ($query) use ($companyId) {
                $query->where('companies.id', $companyId);
            })
            ->where('id', '<>', $user->id)
            ->latest()
            ->paginate($limit);

        return UserResource::collection($users)
            ->additional(['meta' => [
                'user_total_count' => User::whereHas('companies', function ($query) use ($companyId) {
                    $query->where('companies.id', $companyId);
                })->count(),
            ]]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\UserRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(UserRequest $request)
    {
        $this->authorize('create', User::class);

        $user = User::createFromRequest($request);

        return new UserResource($user);
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        return new UserResource($user);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\UserRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $user->updateFromRequest($request);

        return new UserResource($user);
    }

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(DeleteUserRequest $request)
    {
        $this->authorize('delete multiple users', User::class);

        if ($request->users) {
            User::deleteUsers($request->users, $request->header('company'));
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
