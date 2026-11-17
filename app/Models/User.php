<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function uploadedFfbImportBatches(): HasMany
    {
        return $this->hasMany(FfbImportBatch::class, 'uploaded_by');
    }

    public function confirmedFfbImportBatches(): HasMany
    {
        return $this->hasMany(FfbImportBatch::class, 'confirmed_by');
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === Role::ADMIN;
    }

    public function isPegawaiKilang(): bool
    {
        return $this->role?->name === Role::PEGAWAI_KILANG;
    }

    public function isPengurusKilang(): bool
    {
        return $this->role?->name === Role::PENGURUS_KILANG;
    }

    public function isPengurusan(): bool
    {
        return $this->role?->name === Role::PENGURUSAN;
    }

    public function isEksekutifPemasaranBts(): bool
    {
        return $this->role?->name === Role::EKSEKUTIF_PEMASARAN_BTS;
    }

    public function isMillScopedRole(): bool
    {
        return $this->isPegawaiKilang() || $this->isPengurusKilang();
    }

    public function canViewFfbReceiveReports(): bool
    {
        return $this->isAdmin() || $this->isPengurusan() || $this->isEksekutifPemasaranBts();
    }

    public function canViewAllMills(): bool
    {
        return $this->isAdmin() || $this->isPengurusan();
    }

    public function canViewAllReportingMills(): bool
    {
        return $this->canViewAllMills() || $this->isEksekutifPemasaranBts();
    }

    public function canSelectDashboardMill(): bool
    {
        return $this->canViewAllMills() || $this->isEksekutifPemasaranBts();
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
