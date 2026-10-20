<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Demo;
use App\Support\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

// How the example stands in for signing in: pick one of the shop people. Your app has its own sign-in. On a demo, each
// visitor gets a shop of their own instead (App\Support\Demo).
class SignInController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (Demo::on()) {
            Demo::start($request);

            return redirect('/');
        }

        return view('login', ['people' => Shop::people(0)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = User::findOrFail($request->integer('as'));
        abort_unless(0 === $user->shop, 403);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();

        return redirect('/login');
    }
}
