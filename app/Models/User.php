<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'mill_id', 'is_active',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === Role::ADMIN;
    }

    public function isPegawaiKilang(): bool
    {
        return $this->role?->name === Role::PEGAWAI_KILANG;
    }

    public function isPengurusan(): bool
    {
        return $this->role?->name === Role::PENGURUSAN;
    }

    // Pegawai kilang hanya boleh edit, Admin boleh edit & padam, Pengurusan hanya lihat
    public function canEditData(): bool
    {
        return $this->isAdmin() || $this->isPegawaiKilang();
    }

    public function canDeleteData(): bool
    {
        return $this->isAdmin();
    }
}
