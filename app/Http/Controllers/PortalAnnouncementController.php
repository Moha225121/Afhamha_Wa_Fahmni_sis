<?php

namespace App\Http\Controllers;

use App\Services\PortalAnnouncementFeed;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalAnnouncementController extends Controller
{
    public function index(Request $request, PortalAnnouncementFeed $feed): View
    {
        return view('shared.ads.index', [
            'announcements' => $feed->for($request),
            'audience' => $request->user()->role === 'financial_officer' ? 'staff' : $request->user()->role,
        ]);
    }
}
