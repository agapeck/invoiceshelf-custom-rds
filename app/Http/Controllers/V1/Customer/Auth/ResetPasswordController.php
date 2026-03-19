<?php

namespace App\Http\Controllers\V1\Customer\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Password;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    public function reset(Request $request)
    {
        $request->validate($this->rules(), $this->validationErrorMessages());

        $companyId = (int) optional($request->route('company'))->id;
        if ($companyId <= 0) {
            return $this->sendResetFailedResponse($request, Password::INVALID_TOKEN);
        }

        $tokenKey = $this->scopedTokenCacheKey($companyId, (string) $request->email, (string) $request->token);

        if (! Cache::has($tokenKey)) {
            return $this->sendResetFailedResponse($request, Password::INVALID_TOKEN);
        }

        $response = $this->broker()->reset(
            $this->credentials($request),
            function ($user, $password) {
                $this->resetPassword($user, $password);
            }
        );

        if ($response === Password::PASSWORD_RESET) {
            Cache::forget($tokenKey);

            return $this->sendResetResponse($request, $response);
        }

        return $this->sendResetFailedResponse($request, $response);
    }

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::CUSTOMER_HOME;

    public function broker()
    {
        return Password::broker('customers');
    }

    protected function credentials(Request $request): array
    {
        return [
            'email' => $request->input('email'),
            'password' => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
            'token' => $request->input('token'),
            'company_id' => (int) optional($request->route('company'))->id,
        ];
    }

    /**
     * Get the response for a successful password reset.
     *
     * @param  string  $response
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    protected function sendResetResponse(Request $request, $response)
    {
        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Contracts\Auth\CanResetPassword  $user
     * @param  string  $password
     * @return void
     */
    protected function resetPassword($user, $password)
    {
        $user->password = $password;

        $user->setRememberToken(Str::random(60));

        $user->save();

        event(new PasswordReset($user));
    }

    /**
     * Get the response for a failed password reset.
     *
     * @param  string  $response
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    protected function sendResetFailedResponse(Request $request, $response)
    {
        return response('Failed, Invalid Token.', 403);
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
