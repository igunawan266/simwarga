<?php

namespace App\Controllers;

/** The 3D building portal — the application's main navigation surface. */
class PortalController extends BaseController
{
    public function index()
    {
        return view('portal/building', [
            'title' => 'Virtual AI Office',
        ]);
    }
}
