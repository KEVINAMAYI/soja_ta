<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{


    public function register(Request $request)
    {

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|max:255',
            'confirm_password' => 'required|string|max:255|same:password'
        ]);

        $user = new User([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password'))
        ]);


        if ($user->save()) {

            $tokenResult = $user->createToken('Api Token');

            $token = $tokenResult->plainTextToken;

            return response()->json([
                'message' => 'User was successfully registered',
                'token' => $token
            ], 201);

        }

        return response()->json([
            'error' => 'Please Provide Proper Credentials',
        ], 500);

    }



    public function login(Request $request)
    {

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|max:255',
        ]);

        $credentials = request(['email', 'password']);

        if (!Auth::attempt($credentials)) {

            return response()->json([
                'error' => 'User not Authenticated',
            ], 401);

        }

        $user = User::where('email', $request->email)->first();

        $tokenResult = $user->createToken('Api Token');

        $token = $tokenResult->plainTextToken;

        return response()->json([
            'message' => 'Login was successful',
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);

    }


    public function logout(Request $request)
    {

        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'User logged in and out successfully'
        ], 200);
    }

}
