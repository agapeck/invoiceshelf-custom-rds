<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{cid}', function ($user, $cid) {
    return (int) $user->id === (int) $cid;
});

Broadcast::channel('user.{uid}', function ($user, $uid) {
    return (int) $user->id === (int) $uid;
});

Broadcast::channel('company.{companyId}', function ($user, $companyId) {
    if (! $user->hasCompany((int) $companyId)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
