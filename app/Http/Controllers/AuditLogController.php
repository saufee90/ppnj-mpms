<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user')->orderByDesc('created_at')->paginate(30);

        return view('audit.index', compact('logs'));
    }
}
