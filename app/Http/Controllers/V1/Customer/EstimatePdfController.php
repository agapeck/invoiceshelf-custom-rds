<?php

namespace App\Http\Controllers\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\EstimateResource;
use App\Mail\EstimateViewedMail;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Estimate;
use Illuminate\Http\Request;

class EstimatePdfController extends Controller
{
    public function getPdf(EmailLog $emailLog, Request $request)
    {
        $estimate = Estimate::find($emailLog->mailable_id);

        if (! $emailLog->isExpired()) {
            if ($estimate && ($estimate->status == Estimate::STATUS_SENT || $estimate->status == Estimate::STATUS_DRAFT)) {
                $estimate->status = Estimate::STATUS_VIEWED;
                $estimate->save();
                $notifyEstimateViewed = CompanySetting::getSetting(
                    'notify_estimate_viewed',
                    $estimate->company_id
                );

                if ($notifyEstimateViewed == 'YES') {
                    $data['estimate'] = Estimate::findOrFail($estimate->id)->toArray();
                    $customer = Customer::where('company_id', $estimate->company_id)
                        ->find($estimate->customer_id);
                    $data['user'] = $customer ? $customer->toArray() : [];
                    $notificationEmail = CompanySetting::getSetting(
                        'notification_email',
                        $estimate->company_id
                    );

                    \Mail::to($notificationEmail)->send(new EstimateViewedMail($data));
                }
            }

            return $estimate->getGeneratedPDFOrStream('estimate');
        }

        abort(403, 'Link Expired.');
    }

    public function getEstimate(EmailLog $emailLog)
    {
        $estimate = Estimate::find($emailLog->mailable_id);
        if ($estimate) {
            $estimate->load([
                'items',
                'items.taxes',
                'items.fields',
                'items.fields.customField',
                'customer.currency',
                'taxes',
                'creator',
                'fields',
                'fields.customField',
                'company',
                'currency',
            ]);
        }

        return new EstimateResource($estimate);
    }
}
