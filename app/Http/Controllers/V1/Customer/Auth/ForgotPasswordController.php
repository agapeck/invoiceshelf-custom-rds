<?php

namespace App\Http\Controllers\V1\Customer\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Password;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    public function sendResetLinkEmail(Request $request)
    {
        $this->validateEmail($request);

        $credentials = $this->credentials($request);
        if ((int) $credentials['company_id'] <= 0) {
            return $this->sendResetLinkFailedResponse($request, Password::INVALID_USER);
        }

        $customer = $this->broker()->getUser($credentials);

        if (! $customer) {
            return $this->sendResetLinkFailedResponse($request, Password::INVALID_USER);
        }

        $token = $this->broker()->createToken($customer);
        Cache::put(
            $this->scopedTokenCacheKey((int) $credentials['company_id'], (string) $customer->email, $token),
            true,
            now()->addMinutes((int) config('auth.passwords.customers.expire', 60))
        );

        $customer->sendPasswordResetNotification($token);

        return $this->sendResetLinkResponse($request, Password::RESET_LINK_SENT);
    }

    public function broker()
    {
        return Password::broker('customers');
    }

    protected function credentials(Request $request): array
    {
        return [
            'email' => $request->input('email'),
            'company_id' => (int) optional($request->route('company'))->id,
        ];
    }

    /**
     * Get the response for a successful password reset link.
     *
     * @param  string  $response
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    protected function sendResetLinkResponse(Request $request, $response)
    {
        return response()->json([
            'message' => 'Password reset email sent.',
            'data' => $response,
        ]);
    }

    /**
     * Get the response for a failed password reset link.
     *
     * @param  string  $response
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    protected function sendResetLinkFailedResponse(Request $request, $response)
    {
        return response('Email could not be sent to this email address.', 403);
    }

    private function scopedTokenCacheKey(int $companyId, string $email, string $token): string
    {
        return sprintf(
            'customer-password-reset:%d:%s:%s',
            $companyId,
            sha1(strtolower(trim($email))),
            hash('sha256', $token)
        );
    }
}
