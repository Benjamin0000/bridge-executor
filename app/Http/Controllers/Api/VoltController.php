<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Volt; 

class VoltController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function get_volt($volt)
    {
        $volt = Volt::where('network', $volt)->first();
    }
}
