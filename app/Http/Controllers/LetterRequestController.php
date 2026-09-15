<?php

namespace App\Http\Controllers;

use App\Models\LetterRequest;
use App\Models\RtStructure;
use App\Services\LetterSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LetterRequestController extends Controller
{
    protected LetterSequenceService $letterSequenceService;

    public function __construct(LetterSequenceService $letterSequenceService)
    {
        $this->letterSequenceService = $letterSequenceService;
    }

    /**
     * Warga submits a letter request (surat pengantar)
     */
    public function store(Request $request)
    {
        $this->authorize('create', LetterRequest::class);

        $validated = $request->validate([
            'letter_type' => 'required|string|in:surat_pengantar,surat_keterangan,surat_usaha',
            'purpose' => 'required|string',
        ]);

        $wargaProfile = auth()->user()->wargaProfile;

        if (!$wargaProfile) {
            return response()->json(['error' => 'User does not have a warga profile'], 422);
        }

        $letter = LetterRequest::create([
            'warga_profile_id' => $wargaProfile->id,
            'rt_id' => $wargaProfile->rt_id,
            'rw_id' => $wargaProfile->rw_id,
            'letter_type' => $validated['letter_type'],
            'purpose' => $validated['purpose'],
            'request_date' => now()->toDateString(),
            'status' => 'pending_rt',
        ]);

        return response()->json([
            'message' => 'Letter request submitted successfully',
            'data' => $letter,
        ], 201);
    }

    /**
     * List letters for RT officers to review
     */
    public function indexForRT(Request $request)
    {
        $this->authorize('viewForRT', LetterRequest::class);

        $query = LetterRequest::query()
            ->where('rt_id', auth()->user()->rtStructure->id)
            ->whereIn('status', ['pending_rt', 'approved_by_rt', 'rejected_by_rt'])
            ->with('wargaProfile');

        $letters = $query->paginate(20);

        return response()->json([
            'data' => $letters->items(),
            'pagination' => [
                'total' => $letters->total(),
                'per_page' => $letters->perPage(),
                'current_page' => $letters->currentPage(),
                'last_page' => $letters->lastPage(),
            ],
        ]);
    }

    /**
     * RT approves letter and generates sequential number + QR signature
     */
    public function approveForRT(Request $request, LetterRequest $letter)
    {
        $this->authorize('approveForRT', $letter);

        if ($letter->status !== 'pending_rt') {
            return response()->json([
                'error' => 'Letter is not pending RT approval',
            ], 422);
        }

        return DB::transaction(function () use ($request, $letter) {
            // Generate sequential letter number
            $letterNumber = $this->letterSequenceService->generateLetterNumber(
                $letter,
                auth()->user()
            );

            // Generate QR token and signature
            $qrData = $this->letterSequenceService->generateQRTokenForRT(
                $letter,
                auth()->user()
            );

            // Update letter
            $letter->update([
                'status' => 'approved_by_rt',
                'letter_number' => $letterNumber,
                'approved_by_rt_at' => now(),
                'approved_by_rt_user_id' => auth()->user()->id,
                'rt_qr_token' => $qrData['qr_token'],
                'rt_signature_hash' => $qrData['signature_hash'],
            ]);

            return response()->json([
                'message' => 'Letter approved by RT',
                'data' => $letter,
                'qr_svg' => $qrData['qr_svg'],
            ]);
        });
    }

    /**
     * RT rejects letter with reason
     */
    public function rejectForRT(Request $request, LetterRequest $letter)
    {
        $this->authorize('reject', $letter);

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        if ($letter->status !== 'pending_rt') {
            return response()->json([
                'error' => 'Letter is not pending RT approval',
            ], 422);
        }

        $letter->update([
            'status' => 'rejected_by_rt',
            'rejection_reason' => $validated['rejection_reason'],
            'rejected_at' => now(),
            'rejected_by' => auth()->user()->id,
        ]);

        return response()->json([
            'message' => 'Letter rejected by RT',
            'data' => $letter,
        ]);
    }

    /**
     * List letters for RW review (approved by RT)
     */
    public function indexForRW(Request $request)
    {
        $this->authorize('viewForRW', new LetterRequest());

        $query = LetterRequest::query()
            ->where('rw_id', auth()->user()->rwStructure->id)
            ->whereIn('status', ['approved_by_rt', 'approved_by_rw', 'rejected_by_rw'])
            ->with(['wargaProfile', 'rtStructure']);

        $letters = $query->paginate(20);

        return response()->json([
            'data' => $letters->items(),
            'pagination' => [
                'total' => $letters->total(),
                'per_page' => $letters->perPage(),
                'current_page' => $letters->currentPage(),
                'last_page' => $letters->lastPage(),
            ],
        ]);
    }

    /**
     * RW approves letter (counter-signature with QR)
     */
    public function approveForRW(Request $request, LetterRequest $letter)
    {
        $this->authorize('approveForRW', $letter);

        if ($letter->status !== 'approved_by_rt') {
            return response()->json([
                'error' => 'Letter must be approved by RT first',
            ], 422);
        }

        if (!$this->letterSequenceService->verifyQRTokenSignature(
            $letter->rt_qr_token,
            $letter->rt_signature_hash
        )) {
            return response()->json([
                'error' => 'RT QR signature verification failed',
            ], 422);
        }

        return DB::transaction(function () use ($request, $letter) {
            // Generate RW QR token
            $qrData = $this->letterSequenceService->generateQRTokenForRW(
                $letter,
                auth()->user()
            );

            // Update letter
            $letter->update([
                'status' => 'completed',
                'approved_by_rw_at' => now(),
                'approved_by_rw_user_id' => auth()->user()->id,
                'rw_qr_token' => $qrData['qr_token'],
                'rw_signature_hash' => $qrData['signature_hash'],
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Letter completed and approved by RW',
                'data' => $letter,
                'qr_svg' => $qrData['qr_svg'],
            ]);
        });
    }

    /**
     * RW rejects letter with reason
     */
    public function rejectForRW(Request $request, LetterRequest $letter)
    {
        $this->authorize('reject', $letter);

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        if ($letter->status !== 'approved_by_rt') {
            return response()->json([
                'error' => 'Letter must be approved by RT before RW can reject',
            ], 422);
        }

        $letter->update([
            'status' => 'rejected_by_rw',
            'rejection_reason' => $validated['rejection_reason'],
            'rejected_at' => now(),
            'rejected_by' => auth()->user()->id,
        ]);

        return response()->json([
            'message' => 'Letter rejected by RW',
            'data' => $letter,
        ]);
    }

    /**
     * Retrieve completed letter (for PDF generation/download)
     */
    public function show(LetterRequest $letter)
    {
        if ($letter->status !== 'completed') {
            return response()->json([
                'error' => 'Letter is not completed yet',
            ], 422);
        }

        // Warga can view their own letters
        if (auth()->user()->role === 'warga' && auth()->user()->wargaProfile->id !== $letter->warga_profile_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'data' => $letter->load([
                'wargaProfile',
                'rtStructure',
                'rwStructure',
                'approvedByRtUser',
                'approvedByRwUser',
            ]),
        ]);
    }
}
