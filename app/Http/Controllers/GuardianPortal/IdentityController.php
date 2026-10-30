<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Services\ParentIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IdentityController extends Controller
{
    public function create(Request $request, ParentIdentityService $identities): View|RedirectResponse
    {
        $guardian = $request->user()->guardian;
        $options = $identities->options($guardian);
        if (count($options) === 1) {
            return redirect()->route('parent.dashboard');
        }

        return view('parent.identity', ['options' => $options, 'selectedKey' => $identities->selectedKey($request, $guardian)]);
    }

    public function store(Request $request, ParentIdentityService $identities): RedirectResponse
    {
        $guardian = $request->user()->guardian;
        $validated = $request->validate(['identity' => ['required', Rule::in(array_keys($identities->options($guardian)))]]);
        $identities->remember($request, $guardian, $validated['identity']);

        return redirect()->route('parent.dashboard');
    }
}
