<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::with('user')
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $v).'%';
                $q->where(fn ($w) => $w->where('username', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('ip', 'like', $like));
            })
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.audit', ['logs' => $logs, 'actions' => AuditLog::ACTIONS, 'f' => $f]);
    }
}
