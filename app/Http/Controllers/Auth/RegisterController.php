<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\IpCountryResolver;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

class RegisterController extends Controller
{
    use RegistersUsers;

    protected string $redirectTo = '/home';

    public function __construct()
    {
        $this->middleware('guest');
    }

    /** @param array<string, mixed> $data */
    protected function validator(array $data): Validator
    {
        return ValidatorFacade::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function create(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->registration_ip = request()->ip();
        $user->country = app(IpCountryResolver::class)->resolve(request());
        $user->save();

        return $user;
    }
}
