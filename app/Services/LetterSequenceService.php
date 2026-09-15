<?php

namespace App\Services;

use App\Models\LetterRequest;
use App\Models\LetterSequence;
use App\Models\LetterSequenceAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class LetterSequenceService
{
    /**
     * Generate sequential letter number with atomic transaction
     * Format: No. XXX/RT.Y/RW.Z/MM/YYYY
     */
    public function generateLetterNumber(LetterRequest $letter, User $approver): string
    {
        return DB::transaction(function () use ($letter, $approver) {
            // Use row-level locking to prevent race conditions
            $sequence = LetterSequence::query()
                ->where('rt_id', $letter->rt_id)
                ->where('rw_id', $letter->rw_id)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = LetterSequence::create([
                    'rt_id' => $letter->rt_id,
                    'rw_id' => $letter->rw_id,
                    'last_sequence_number' => 0,
                    'last_sequence_month' => now()->month,
                    'last_sequence_year' => now()->year,
                ]);
            }

            $currentMonth = now()->month;
            $currentYear = now()->year;

            // Reset sequence if month/year changed
            if ($sequence->last_sequence_month !== $currentMonth || $sequence->last_sequence_year !== $currentYear) {
                $sequence->last_sequence_number = 0;
                $sequence->last_sequence_month = $currentMonth;
                $sequence->last_sequence_year = $currentYear;
            }

            // Increment sequence
            $sequence->last_sequence_number++;
            $sequence->save();

            // Format letter number
            $rtNumber = $letter->rtStructure->rt_number;
            $rwNumber = $letter->rwStructure->rw_number;
            $sequenceNumber = str_pad($sequence->last_sequence_number, 3, '0', STR_PAD_LEFT);
            $month = str_pad($currentMonth, 2, '0', STR_PAD_LEFT);
            $year = $currentYear;

            $letterNumber = "No. {$sequenceNumber}/RT.{$rtNumber}/RW.{$rwNumber}/{$month}/{$year}";

            // Audit trail
            LetterSequenceAudit::create([
                'letter_request_id' => $letter->id,
                'rt_id' => $letter->rt_id,
                'rw_id' => $letter->rw_id,
                'generated_letter_number' => $letterNumber,
                'sequence_number' => $sequence->last_sequence_number,
                'generated_by' => $approver->id,
            ]);

            return $letterNumber;
        });
    }

    /**
     * Generate QR token with signature hash for RT approval
     */
    public function generateQRTokenForRT(LetterRequest $letter, User $approver): array
    {
        $payload = [
            'letter_id' => $letter->id,
            'letter_number' => $letter->letter_number,
            'warga_nik' => $letter->wargaProfile->nik,
            'rt_id' => $letter->rt_id,
            'approved_by_rt_user_id' => $approver->id,
            'approved_at' => now()->toIso8601String(),
            'verification_type' => 'rt_approval',
        ];

        $payloadJson = json_encode($payload);
        $signatureHash = hash_hmac('sha256', $payloadJson, config('app.key'));

        $qrPayload = [
            'payload' => $payload,
            'signature' => $signatureHash,
        ];

        $qrCode = QrCode::format('svg')->size(300)->generate(json_encode($qrPayload));

        return [
            'qr_token' => json_encode($qrPayload),
            'signature_hash' => $signatureHash,
            'qr_svg' => $qrCode,
        ];
    }

    /**
     * Generate QR token with signature hash for RW approval
     */
    public function generateQRTokenForRW(LetterRequest $letter, User $approver): array
    {
        $payload = [
            'letter_id' => $letter->id,
            'letter_number' => $letter->letter_number,
            'warga_nik' => $letter->wargaProfile->nik,
            'rw_id' => $letter->rw_id,
            'rt_qr_verified' => true,
            'rt_signature_hash' => $letter->rt_signature_hash,
            'approved_by_rw_user_id' => $approver->id,
            'approved_at' => now()->toIso8601String(),
            'verification_type' => 'rw_approval',
        ];

        $payloadJson = json_encode($payload);
        $signatureHash = hash_hmac('sha256', $payloadJson, config('app.key'));

        $qrPayload = [
            'payload' => $payload,
            'signature' => $signatureHash,
        ];

        $qrCode = QrCode::format('svg')->size(300)->generate(json_encode($qrPayload));

        return [
            'qr_token' => json_encode($qrPayload),
            'signature_hash' => $signatureHash,
            'qr_svg' => $qrCode,
        ];
    }

    /**
     * Verify QR token signature
     */
    public function verifyQRTokenSignature(string $qrToken, string $signatureHash): bool
    {
        $decoded = json_decode($qrToken, true);
        if (!$decoded || !isset($decoded['payload'])) {
            return false;
        }

        $payloadJson = json_encode($decoded['payload']);
        $expectedHash = hash_hmac('sha256', $payloadJson, config('app.key'));

        return hash_equals($expectedHash, $signatureHash);
    }
}
