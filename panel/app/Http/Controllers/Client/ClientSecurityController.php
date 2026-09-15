<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\TwoFactorController;
use App\Services\Clients\ClientContext;
use App\Services\TwoFactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** Password and two-factor authentication of the signed-in client. */
class ClientSecurityController extends TwoFactorController
{
    protected function account(Request $request): Model
    {
        return ClientContext::current() ?? abort(401);
    }

    protected function viewContext(): array
    {
        return ['layout' => 'client.layout', 'routePrefix' => 'client.', 'required' => false, 'lostHint' => 'contact your hosting provider to reset it.'];
    }

    protected function isRequired(): bool
    {
        return false;
    }

    protected function endOtherSessions(Model $account, Request $request): void
    {
        // client sessions are not indexed by account; the remember token is rotated instead
        $account->setRememberToken(\Illuminate\Support\Str::random(60));
        $account->save();
    }

    public function disable(Request $request)
    {
        $response = parent::disable($request);
        Cookie::queue(Cookie::forget(TwoFactor::TRUST_COOKIE.'_client'));

        return $response;
    }

    public function forgetBrowser()
    {
        Cookie::queue(Cookie::forget(TwoFactor::TRUST_COOKIE.'_client'));

        return $this->ok('This browser will ask for a code at the next sign-in');
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $client = $this->account($request);
        if (! Hash::check($data['current_password'], $client->password)) {
            return $this->fail('Current password is incorrect.');
        }
        $client->update(['password' => $data['password']]);
        $this->endOtherSessions($client, $request);
        $this->audit('client', 'Changed own password');

        return $this->ok('Password changed');
    }
}
