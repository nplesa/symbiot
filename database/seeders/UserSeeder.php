<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Plesa Nicolae',
                'email' => 'nicu.plesa@gmail.com',
                'password' => Hash::make(Str::random(20)),
                'is_admin' => true,
                'approved_at' => now(),
            ],
            [
                'name' => 'Nicu Plesa',
                'email' => 'nicolae.plesa.a7@gmail.com',
                'password' => Hash::make(Str::random(20)),
                'is_admin' => true,
                'approved_at' => now(),
            ],
            [
                'name' => 'Claudia Plesa',
                'email' => 'plesaclaudia2004@yahoo.com',
                'password' => Hash::make(Str::random(20)),
            ],
            [
                'name' => 'Ana Maria Plesa',
                'email' => 'anamaria2014.plesa61@gmail.com',
                'password' => Hash::make(Str::random(20)),
            ],
        ];
        foreach ($users as $user) {
            User::create($user);
        }
    }
}
