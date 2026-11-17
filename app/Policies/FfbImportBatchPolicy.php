<?php

namespace App\Policies;

use App\Models\FfbImportBatch;
use App\Models\User;

class FfbImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && (
            $user->canViewFfbReceiveReports()
            || ($user->isMillScopedRole() && $user->mill_id !== null)
        );
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->canEditData()
            && ($user->isAdmin() || (bool) $user->mill?->is_active);
    }

    public function view(User $user, FfbImportBatch $batch): bool
    {
        return $this->viewAny($user) && (
            $user->canViewFfbReceiveReports() || (int) $user->mill_id === $batch->mill_id
        );
    }

    public function confirm(User $user, FfbImportBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->canEditData()
            && (bool) $batch->mill?->is_active;
    }

    public function correct(User $user, FfbImportBatch $batch): bool
    {
        return $this->confirm($user, $batch)
            && $batch->status === 'confirmed'
            && $batch->receipt_date?->toDateString() >= '2026-11-05'
            && $batch->receipt_date?->toDateString() <= now('Asia/Kuala_Lumpur')->toDateString();
    }

    public function confirmCorrection(User $user, FfbImportBatch $batch): bool
    {
        if (! $user->is_active || ! $user->canEditData() || $batch->status !== 'ready'
            || $batch->supersedes_batch_id === null || trim((string) $batch->confirmation_reason) === '') {
            return false;
        }

        $predecessor = FfbImportBatch::with('mill')->find($batch->supersedes_batch_id);

        return $predecessor !== null
            && $predecessor->mill_id === $batch->mill_id
            && $predecessor->receipt_date->toDateString() === $batch->receipt_date->toDateString()
            && $this->correct($user, $predecessor);
    }
}
