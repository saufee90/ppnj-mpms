<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index()
    {
        $users = User::with(['role', 'mill'])->orderBy('name')->get();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::all();
        $mills = Mill::where('is_active', true)->get();

        return view('users.create', compact('roles', 'mills'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role_id' => ['required', 'exists:roles,id'],
            'mill_id' => ['nullable', 'exists:mills,id'],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = true;

        $user = User::create($validated);

        AuditLog::record('created', $user, null, ['name' => $user->name, 'email' => $user->email]);

        return redirect()->route('users.index')->with('success', 'Pengguna berjaya ditambah.');
    }

    public function edit(User $user)
    {
        $roles = Role::all();
        $mills = Mill::where('is_active', true)->get();

        return view('users.edit', compact('user', 'roles', 'mills'));
    }

    public function update(User $user, Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role_id' => ['required', 'exists:roles,id'],
            'mill_id' => ['nullable', 'exists:mills,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }
        $validated['is_active'] = $request->boolean('is_active');

        $old = $user->only(['name', 'email', 'role_id', 'mill_id', 'is_active']);
        $user->update($validated);

        AuditLog::record('updated', $user, $old, $user->only(['name', 'email', 'role_id', 'mill_id', 'is_active']));

        return redirect()->route('users.index')->with('success', 'Pengguna berjaya dikemaskini.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak boleh memadam akaun sendiri.');
        }

        AuditLog::record('deleted', $user, ['name' => $user->name, 'email' => $user->email], null);

        $user->delete();

        return redirect()->route('users.index')->with('success', 'Pengguna berjaya dipadam.');
    }
}
