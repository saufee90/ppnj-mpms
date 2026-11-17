<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\FfbImportBatch;
use App\Models\Mill;
use App\Services\FfbReceiveImportService;
use App\Services\FfbBatchConfirmationService;
use App\Services\FfbBatchCorrectionService;
use App\Support\FfbReceivePresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FfbReceiveController extends Controller
{
    public function zeroConfirmation(Request $request, FfbBatchConfirmationService $confirmation): View
    {
        Gate::authorize('create', FfbImportBatch::class);
        $input = $this->zeroInput($request);
        $mill = Mill::findOrFail($input['mill_id']);
        $summary = $confirmation->zeroSummary($mill->id, $input['receipt_date']);

        return view('ffb-receive.zero-confirmation', compact('mill', 'input', 'summary'));
    }

    public function confirmZero(Request $request, FfbBatchConfirmationService $confirmation): RedirectResponse
    {
        Gate::authorize('create', FfbImportBatch::class);
        $input = $this->zeroInput($request);
        $validated = $request->validate([
            'acknowledgement' => ['required', 'accepted'],
            'confirmation_token' => ['required', 'string', 'size:64'],
        ]);
        $batch = $confirmation->confirmZero((int) $input['mill_id'], $input['receipt_date'],
            $request->user(), $validated['confirmation_token']);

        return redirect()->route('ffb-receive.show', $batch)->with('success', 'Tiada penerimaan BTS disahkan: 0.00 MT. Rekod dikunci.');
    }

    private function zeroInput(Request $request): array
    {
        return $request->validate([
            'mill_id' => ['required', 'integer', Rule::exists('mills', 'id')->where('is_active', true),
                ...($request->user()->isMillScopedRole() ? [Rule::in([$request->user()->mill_id])] : [])],
            'receipt_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.now('Asia/Kuala_Lumpur')->toDateString()],
        ]);
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', FfbImportBatch::class);
        $user = $request->user();
        $mills = Mill::query()
            ->when($user->isMillScopedRole(), fn ($query) => $query->whereKey($user->mill_id))
            ->orderBy('name')->get();
        $batches = FfbImportBatch::with(['mill', 'uploader', 'confirmer'])
            ->when($user->isMillScopedRole(), fn ($query) => $query->where('mill_id', $user->mill_id))
            ->orderByDesc('id')->paginate(20);

        return view('ffb-receive.index', compact('mills', 'batches'));
    }

    public function store(Request $request, FfbReceiveImportService $imports): RedirectResponse
    {
        Gate::authorize('create', FfbImportBatch::class);
        $user = $request->user();
        $validated = $request->validate([
            'mill_id' => [
                'required', 'integer',
                Rule::exists('mills', 'id')->where('is_active', true),
                ...($user->isMillScopedRole() ? [Rule::in([$user->mill_id])] : []),
            ],
            'receipt_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.now('Asia/Kuala_Lumpur')->toDateString()],
            'workbook' => [
                'required', 'file', 'max:10240', 'extensions:xls,xlsx',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value instanceof \Illuminate\Http\UploadedFile && mb_strlen($value->getClientOriginalName()) > 255) {
                        $fail('Nama fail mesti tidak melebihi 255 aksara.');
                    }
                },
            ],
            'confirmed_weight_unit' => ['nullable', Rule::in(['KG', 'MT'])],
            'control_ticket_count' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'control_total_mt' => ['nullable', 'numeric', 'min:0', 'max:999999999.999999', 'decimal:0,6'],
        ]);

        $batch = $imports->import($request->file('workbook'), $user, $validated);

        return redirect()->route('ffb-receive.show', $batch)
            ->with($batch->status === 'failed' ? 'error' : 'success', $batch->status === 'failed'
                ? 'Import gagal. Fail disimpan untuk semakan; tiada rekod harian diubah.'
                : 'Fail diproses untuk pratonton sahaja. Tiada pengesahan atau perubahan rekod harian.');
    }

    public function show(FfbImportBatch $ffb_import_batch, FfbBatchCorrectionService $corrections): View
    {
        Gate::authorize('view', $ffb_import_batch);
        $batch = $ffb_import_batch->load(['mill', 'uploader', 'confirmer']);
        $tickets = $batch->tickets()->orderBy('id')->paginate(50);
        $categories = [];
        foreach (FfbReceivePresentation::CATEGORY_LABELS as $category => $label) {
            $query = $batch->tickets()->where('source_category', $category);
            $categories[] = [
                'label' => $label, 'count' => (clone $query)->count(),
                'total_mt' => $query->sum('weight_mt'),
            ];
        }
        $unclassified = $batch->tickets()->whereNull('source_category')->count();
        $confirmed = FfbImportBatch::where('mill_id', $batch->mill_id)
            ->whereDate('receipt_date', $batch->receipt_date)->where('status', 'confirmed')
            ->whereKeyNot($batch->id)->first();
        $dailyOperations = DailyOperation::where('mill_id', $batch->mill_id)
            ->whereDate('tarikh', $batch->receipt_date)->orderBy('shift')->get();
        $correctionHistory = $corrections->history($batch);
        $supersededBy = FfbImportBatch::where('supersedes_batch_id', $batch->id)
            ->whereIn('status', ['confirmed', 'superseded'])->orderBy('id')->first();
        $correctionComparison = $batch->supersedes_batch_id
            ? $corrections->comparison(FfbImportBatch::findOrFail($batch->supersedes_batch_id), $batch)
            : null;

        return view('ffb-receive.show', compact(
            'batch', 'tickets', 'categories', 'unclassified', 'confirmed', 'dailyOperations',
            'correctionHistory', 'correctionComparison', 'supersededBy',
        ));
    }

    public function createCorrection(FfbImportBatch $ffb_import_batch): View
    {
        Gate::authorize('correct', $ffb_import_batch);

        return view('ffb-receive.correction-create', ['batch' => $ffb_import_batch->load('mill')]);
    }

    public function storeCorrection(
        Request $request,
        FfbImportBatch $ffb_import_batch,
        FfbReceiveImportService $imports,
        FfbBatchCorrectionService $corrections,
    ): RedirectResponse {
        Gate::authorize('correct', $ffb_import_batch);
        $validated = $request->validate([
            'receipt_type' => ['required', Rule::in(['excel', 'zero_receipt'])],
            'correction_reason' => ['required', 'string', 'min:5', 'max:500'],
            'workbook' => [
                Rule::requiredIf($request->input('receipt_type') === 'excel'),
                Rule::prohibitedIf($request->input('receipt_type') === 'zero_receipt'),
                'nullable', 'file', 'max:10240', 'extensions:xls,xlsx',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value instanceof \Illuminate\Http\UploadedFile && mb_strlen($value->getClientOriginalName()) > 255) {
                        $fail('Nama fail mesti tidak melebihi 255 aksara.');
                    }
                },
            ],
            'confirmed_weight_unit' => ['nullable', Rule::in(['KG', 'MT'])],
            'control_ticket_count' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'control_total_mt' => ['nullable', 'numeric', 'min:0', 'max:999999999.999999', 'decimal:0,6'],
        ]);

        if ($validated['receipt_type'] === 'zero_receipt') {
            $candidate = $corrections->createZeroReplacement(
                $ffb_import_batch->id,
                $request->user(),
                $validated['correction_reason'],
            );
        } else {
            $candidate = $imports->import(
                $request->file('workbook'),
                $request->user(),
                [
                    'mill_id' => $ffb_import_batch->mill_id,
                    'receipt_date' => $ffb_import_batch->receipt_date->toDateString(),
                    'confirmed_weight_unit' => $request->input('confirmed_weight_unit'),
                    'control_ticket_count' => $request->input('control_ticket_count'),
                    'control_total_mt' => $request->input('control_total_mt'),
                ],
                $ffb_import_batch,
                $validated['correction_reason'],
            );
        }

        return redirect()->route('ffb-receive.correction.confirmation', $candidate)
            ->with('success', 'Calon pembetulan disediakan. Semak perbandingan sebelum mengesahkan.');
    }

    public function correctionConfirmation(
        FfbImportBatch $candidate,
        FfbBatchConfirmationService $confirmation,
    ): View {
        Gate::authorize('confirmCorrection', $candidate);
        $candidate->load(['mill', 'uploader']);
        $predecessor = FfbImportBatch::findOrFail($candidate->supersedes_batch_id);
        $summary = $confirmation->summaryCorrection($candidate);

        return view('ffb-receive.correction-confirmation', compact('candidate', 'predecessor', 'summary'));
    }

    public function confirmCorrection(
        Request $request,
        FfbImportBatch $candidate,
        FfbBatchConfirmationService $confirmation,
    ): RedirectResponse {
        Gate::authorize('confirmCorrection', $candidate);
        $validated = $request->validate([
            'acknowledgement' => ['required', 'accepted'],
            'confirmation_token' => ['required', 'string', 'size:64'],
        ], [
            'acknowledgement.accepted' => 'Sila akui perbandingan sebelum dan selepas sebelum mengesahkan pembetulan.',
        ]);
        $batch = $confirmation->confirmCorrection(
            $candidate->id,
            $request->user(),
            $validated['confirmation_token'],
        );

        return redirect()->route('ffb-receive.show', $batch)
            ->with('success', 'Pembetulan batch FFB berjaya disahkan dan sejarah asal dikekalkan.');
    }

    public function confirmation(FfbImportBatch $ffb_import_batch, FfbBatchConfirmationService $confirmation): View
    {
        Gate::authorize('confirm', $ffb_import_batch);
        $batch = $ffb_import_batch->load('mill');
        $summary = $confirmation->summary($batch);

        return view('ffb-receive.confirmation', compact('batch', 'summary'));
    }

    public function confirm(
        Request $request,
        FfbImportBatch $ffb_import_batch,
        FfbBatchConfirmationService $confirmation,
    ): RedirectResponse {
        Gate::authorize('confirm', $ffb_import_batch);
        $validated = $request->validate([
            'acknowledgement' => ['required', 'accepted'],
            'confirmation_token' => ['required', 'string', 'size:64'],
        ], ['acknowledgement.accepted' => 'Sila akui semakan tiket, jumlah, kategori dan semua amaran sebelum mengesahkan.']);
        $batch = $confirmation->confirm($ffb_import_batch->id, $request->user(), $validated['confirmation_token']);

        return redirect()->route('ffb-receive.show', $batch)
            ->with('success', 'Penerimaan BTS berjaya disahkan. Rekod harian dan KPI tidak diubah.');
    }
}
