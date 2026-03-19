<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function mailable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired()
    {
        $mailable = $this->mailable;
        if (! $mailable || ! isset($mailable->company_id)) {
            return true;
        }

        $companyId = (int) $mailable->company_id;
        $linkExpiryDays = (int) CompanySetting::getSetting('link_expiry_days', $companyId);
        $checkExpiryLinks = CompanySetting::getSetting('automatically_expire_public_links', $companyId);

        $expiryDate = $this->created_at->addDays($linkExpiryDays);

        if ($checkExpiryLinks == 'YES' && Carbon::now()->format('Y-m-d') > $expiryDate->format('Y-m-d')) {
            return true;
        }

        return false;
    }
}
