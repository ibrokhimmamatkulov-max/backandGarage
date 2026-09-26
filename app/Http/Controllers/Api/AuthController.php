<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\OAuth2Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'login'    => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                $this->logAuth('warning', 'Login rejected: validation failed', $request, [
                    'errors' => $validator->errors()->toArray(),
                ]);
                return $this->error('Unauthorized', 401);
            }

            $user = User::with('roles')->where('login', $request->login)->first();

            if (!$user) {
                $this->logAuth('warning', 'Login rejected: user not found', $request);
                return $this->error('Unauthorized', 401);
            }

            if (!$user->status) {
                $this->logAuth('warning', 'Login rejected: user is inactive', $request, [
                    'user_id' => $user->id,
                ]);
                return $this->error('Unauthorized', 401);
            }

            if (!Hash::check($request->password, $user->password)) {
                $this->logAuth('warning', 'Login rejected: invalid password', $request, [
                    'user_id' => $user->id,
                ]);
                return $this->error('Unauthorized', 401);
            }

            $token = (new OAuth2Service)->token($request->all());

            if (!isset($token['data'])) {
                $this->logAuth('error', 'Login rejected: token issuance failed', $request, [
                    'user_id'      => $user->id,
                    'oauth_result' => $token,
                ]);
                return $this->error('Unauthorized', 401);
            }

            $token = $token['data'];
            $token['created_at'] = Carbon::now()->toDateTimeString();

            $this->logAuth('info', 'Login successful', $request, ['user_id' => $user->id]);

            return $this->success($this->getAuthData($user, $token), 'Login successful');
        } catch (\Exception $e) {
            $this->logAuth('error', 'Login failed: unhandled exception', $request, [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return $this->error('Unauthorized', 401);
        }
    }

    /**
     * Write a structured, readable entry to the dedicated auth log channel.
     * Never includes the raw password, only the attempted login and request metadata.
     */
    private function logAuth(string $level, string $message, Request $request, array $context = []): void
    {
        Log::channel('auth')->log($level, $message, array_merge([
            'login'      => $request->input('login'),
            'ip'         => $request->header('x-forwarded-for') ?? $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $context));
    }

    private function getAuthData(User $user, $token): array
    {
        $role_id = $user->roles()->pluck('id')->first();

        $user_info = [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'patronymic' => $user->patronymic,
        ];

        return [
            'id'                    => $user->id,
            'user_id'               => $user->id,
            'token_type'            => $token['token_type'],
            'access_token'          => $token['access_token'],
            'access_token_expires'  => $token['expires_in'],
            'access_token_expDate'  => Carbon::now()->addSeconds($token['expires_in'])->toDateTimeString(),
            'refresh_token'         => $token['refresh_token'],
            'role_id'               => $role_id,
            'user_info'             => $user_info,
            'created_at'            => $token['created_at'],
            'role_ru'               => $user?->roles()->first()?->display_name,
        ];
    }
}
