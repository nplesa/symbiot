<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Plesa Nicolae',
                'email' => 'nicu.plesa@gmail.com',
                'password' => Hash::make('Tinpen99'),
            ],
            [
                'name' => 'Nicu Plesa',
                'email' => 'nicolae.plesa.a7@gmail.com',
                'password' => Hash::make('Tinpen99'),
            ],
            [
                'name' => 'Claudia Plesa',
                'email' => 'plesaclaudia2004@yahoo.com',
                'password' => Hash::make('Tinpen99'),
            ],
            [
                'name' => 'Ana Maria Plesa',
                'email' => 'anamaria2014.plesa61@gmail.com',
                'password' => Hash::make('Tinpen99'),
            ],
        ];
        foreach ($users as $user) {
            User::create($user);
        }
    }
}
