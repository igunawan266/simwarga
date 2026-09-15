# SIMWarga Implementation Guide

## Architecture Overview

This document provides comprehensive implementation details, race condition analysis, and deployment guidelines for the SIMWarga Community Management & Dues system.

---

## 1. Race Condition & Concurrency Analysis

### 1.1 Sequential Letter Number Generation (CRITICAL)

**Problem Statement:**
Multiple RT officers might simultaneously approve letters, causing duplicate sequence numbers or skipped numbers.

**Solution Implemented:**
```php
// Using Laravel's pessimistic locking with FOR UPDATE
$sequence = LetterSequence::query()
    ->where('rt_id', $letter->rt_id)
    ->where('rw_id', $letter->rw_id)
    ->lockForUpdate()  // Row-level exclusive lock
    ->first();
```

**Why This Works:**
- `lockForUpdate()` acquires an exclusive lock on the row
- Only one transaction can hold the lock at a time
- Prevents dirty reads and ensures atomic increment
- Lock is automatically released at transaction commit/rollback

**Performance Consideration:**
- Lock contention occurs during peak approval times
- Typical RT has 1-3 approval officers, so contention is minimal
- If contention becomes issue, add Read Replicas with priority routing for reads

**Alternative Approaches:**
1. **Database Trigger (MySQL):** Atomic increment in BEFORE INSERT trigger
2. **Sequence Table with AUTO_INCREMENT:** Less portable, MySQL-specific
3. **Redis/Memcached:** Fast but requires additional infrastructure

### 1.2 Role Assignment Enforcement (Single Role Constraint)

**Problem:**
If validation happens only at application level, invalid double-role assignments could occur via direct SQL or API bugs.

**Solution:**
```sql
-- Database constraint - enforces at storage level
UNIQUE KEY uq_user_role (user_id, role_id)
```

**Multi-Layer Defense:**
1. **Database Layer:** UNIQUE constraint prevents duplicates
2. **Application Layer:** `EnforceSingleRole` middleware
3. **Before Assigning:** `user->assignRole()` detaches previous roles first

### 1.3 Family Member Concurrency

**Scenario:** Head of Household adds family member while family member adds themselves

**Protection:**
- `family_card_id` Foreign Key with CASCADE delete
- Application-level validation: only KK head or RT can add members
- Each member has unique `nik` (national ID)

### 1.4 Dues Payment Race Condition

**Scenario:** Payment partially recorded, then network disconnect

**Protection:**
```sql
UNIQUE KEY uq_billing_warga (billing_id, warga_profile_id)
```
- Prevents duplicate payment records
- Use UPSERT (INSERT ... ON DUPLICATE KEY UPDATE) for retry safety

---

## 2. Data Scope & Privacy Implementation

### 2.1 Cross-RT Search (Global Search vs Scoped View)

**Business Rule:**
- RT can search for citizens across entire RW by Name/KTP (for admin reference)
- But cannot view full profiles/financial details of other RT citizens

**Implementation:**

```php
// In WargaProfileController
public function searchCrossRT(Request $request)
{
    $this->authorize('viewAny', WargaProfile::class);
    
    $query = WargaProfile::where('rw_id', auth()->user()->rwStructure->id)
        ->select('id', 'nik', 'nama_lengkap', 'rt_id'); // Minimal fields
    
    if ($request->has('nik')) {
        $query->where('nik', $request->nik);
    }
    
    if ($request->has('nama')) {
        $query->where('nama_lengkap', 'like', '%' . $request->nama . '%');
    }
    
    return $query->limit(50)->get();
}

// In WargaProfileController for full profile view
public function show(WargaProfile $profile)
{
    $this->authorize('view', $profile); // Policy enforces RT scope
    return response()->json($profile);
}
```

**Policy Logic (WargaProfilePolicy):**
```php
public function view(User $user, WargaProfile $profile): bool
{
    if ($user->role === 'admin') return true;
    if ($user->id === $profile->user_id) return true;

    // RT officers can view profiles ONLY in their own RT
    if (in_array($user->role, ['ketua_rt', 'sekretaris_rt']) && 
        $user->rtStructure?->id === $profile->rt_id) {
        return true;
    }

    return false;
}
```

### 2.2 Financial Report Isolation

**Rules:**
- RT financial reports scoped to single RT
- RW financial reports scoped to entire RW
- RT cannot view other RT's financial data
- RW can view all RT reports + RW summary

**Query Scopes:**

```php
// In FinancialTransaction model
public function scopeByRt($query, $rtId)
{
    return $query->where('scope', 'rt')->where('rt_id', $rtId);
}

public function scopeByRw($query, $rwId)
{
    return $query->where('scope', 'rw')->where('rw_id', $rwId);
}

// Usage
FinancialTransaction::byRt($user->rtStructure->id)->get();
```

---

## 3. Letter Workflow State Machine

### 3.1 Status Transitions

```
pending_rt (Warga submits)
    ↓
[Ketua RT or Sekretaris RT approves/rejects]
    ├→ approved_by_rt + generates letter number + QR signature
    └→ rejected_by_rt + stores rejection reason
    
    [If approved_by_rt]
    ↓
[Ketua RW verifies RT QR + approves/rejects]
    ├→ completed + RW counter-signature + QR verification
    └→ rejected_by_rw + stores rejection reason
    
    [If completed]
    ↓
[Warga downloads PDF]
```

### 3.2 Letter Number Generation Format

```
No. 001/RT.02/RW.03/04/2026
      ↓    ↓    ↓    ↓  ↓
      │    │    │    │  └─ Year
      │    │    │    └────── Month
      │    │    └─────────── RW Number
      │    └────────────────  RT Number
      └────────────────────── Sequence Number (resets monthly)
```

**Monthly Reset Logic:**
- Each RT/RW has separate sequence counter
- Counter resets on month change
- Stored in `letter_sequences` table with month/year tracking

---

## 4. QR Signature & Verification

### 4.1 QR Token Structure

```json
{
  "payload": {
    "letter_id": 42,
    "letter_number": "No. 001/RT.02/RW.03/04/2026",
    "warga_nik": "1234567890123456",
    "rt_id": 5,
    "approved_by_rt_user_id": 12,
    "approved_at": "2026-04-15T10:30:00Z",
    "verification_type": "rt_approval"
  },
  "signature": "a7f3e2d1c9b8f7a6e5d4c3b2a1f0e9d8"
}
```

### 4.2 Signature Generation (HMAC-SHA256)

```php
$payloadJson = json_encode($payload);
$signatureHash = hash_hmac('sha256', $payloadJson, config('app.key'));
```

**Security Properties:**
- Uses application secret key (`APP_KEY` from .env)
- Prevents signature forgery without key
- Verifiable offline (no server call needed)
- Tamper-evident: any change breaks signature

### 4.3 QR Code Generation

```php
$qrCode = QrCode::format('svg')->size(300)->generate(
    json_encode(['payload' => $payload, 'signature' => $signatureHash])
);
```

**QR Code Contents:**
- Entire JSON payload + signature
- SVG format for document embedding
- 300x300px readable at standard print sizes
- Scannable with standard QR readers

### 4.4 Verification Process

```php
// When RW reviews letter
$decoded = json_decode($letter->rt_qr_token, true);
$payloadJson = json_encode($decoded['payload']);
$expectedHash = hash_hmac('sha256', $payloadJson, config('app.key'));

if (!hash_equals($expectedHash, $letter->rt_signature_hash)) {
    // Signature tampered or invalid
    abort(422, 'QR signature verification failed');
}
```

---

## 5. Bootstrap Initialization Flow

### 5.1 Admin Initial Setup

**Step 1: Create Initial Users & RW Structure**
```php
// Database Seeder
$admin = User::create(['email' => 'admin@simwarga.local', ...]);
$admin->assignRole('admin');

$rwStructure = RwStructure::create([
    'rw_number' => 1,
    'name' => 'RW 01 Kelurahan X',
    'total_rt_units' => 10,
]);
```

**Step 2: Create RT Structures & Assign RT Officers**
```php
for ($rtNum = 1; $rtNum <= 10; $rtNum++) {
    $rtStructure = RtStructure::create([
        'rw_id' => $rwStructure->id,
        'rt_number' => $rtNum,
    ]);
}

// Assign Ketua RW
$ketuaRwUser = User::create([...]);
$ketuaRwUser->assignRole('rw');
$rwStructure->update(['ketua_rw_user_id' => $ketuaRwUser->id]);
```

**Step 3: RW Assigns RT Leadership**
```php
// After RW login, assign Ketua RT, Sekretaris RT, Bendahara RT
$ketuaRtUser->assignRole('ketua_rt');
$rtStructure->update(['ketua_rt_user_id' => $ketuaRtUser->id]);

$sekretarisRtUser->assignRole('sekretaris_rt');
$rtStructure->update(['sekretaris_rt_user_id' => $sekretarisRtUser->id]);

$bendaharaRtUser->assignRole('bendahara_rt');
$rtStructure->update(['bendahara_rt_user_id' => $bendaharaRtUser->id]);
```

**Step 4: RT Adds Citizens**
```php
// Only Ketua RT and Sekretaris RT can add primary citizens (Kepala Keluarga)
$ketuaRt = auth()->user();
$ketuaRt->can('create', WargaProfile::class); // Must be true

$profile = WargaProfile::create([
    'user_id' => $citizenUser->id,
    'nik' => '...',
    'nama_lengkap' => '...',
    'is_head_of_family' => true,
    'rt_id' => $ketuaRt->rtStructure->id,
    'rw_id' => $rwStructure->id,
]);

// Create Family Card (KK)
$familyCard = FamilyCard::create([
    'head_of_family_id' => $profile->id,
    'no_kk' => '...',
    'rt_id' => $rtStructure->id,
    'rw_id' => $rwStructure->id,
]);
```

---

## 6. Authorization Matrix (Policies)

### 6.1 Warga Profile Access Control

| Role | View Own | View RT | View Cross-RT (search) | Create | Edit Own | Add Family Members |
|------|----------|---------|------------------------|--------|----------|-------------------|
| Warga | ✓ | ✗ | ✗ | ✗ | ✓ | ✓ (their own family) |
| Ketua RT | ✓ | ✓ | ✓ (list only) | ✓ | ✓ | N/A |
| Sekretaris RT | ✓ | ✓ | ✓ (list only) | ✓ | ✓ | N/A |
| Ketua RW | ✓ | ✓ (all) | ✓ (all) | ✓ | ✓ | N/A |
| Admin | ✓ | ✓ (all) | ✓ (all) | ✓ | ✓ | N/A |

### 6.2 Letter Request Access Control

| Role | Submit | View RT Queue | Approve RT | Reject RT | View RW Queue | Approve RW | Reject RW | Download |
|------|--------|--------------|-----------|----------|--------------|-----------|----------|----------|
| Warga | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ (own) |
| Ketua RT | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Sekretaris RT | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Ketua RW | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✗ |
| Admin | ✓ | ✓ (all) | ✓ (all) | ✓ (all) | ✓ (all) | ✓ (all) | ✓ (all) | ✓ (all) |

### 6.3 Financial Transaction Access Control

| Role | View Own RT | View Own RW | Create RT | Create RW | Edit Own | Delete Own |
|------|------------|-----------|-----------|-----------|----------|-----------|
| Bendahara RT | ✓ | ✗ | ✓ | ✗ | ✓ | ✓ |
| Ketua RT | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Ketua RW | ✓ (all) | ✓ | ✗ | ✓ | ✓ | ✓ |
| Admin | ✓ (all) | ✓ (all) | ✓ | ✓ | ✓ | ✓ |

---

## 7. Key Validations & Business Rules

### 7.1 Citizen Entry Rules

```php
// Can only add Kepala Keluarga (Head of Household)
if (!$validated['is_head_of_family']) {
    return response()->json(['error' => 'Can only add Heads of Household'], 422);
}

// Cannot add non-heads as standalone entries
$existingHeads = WargaProfile::where('family_card_id', $familyCardId)
    ->where('is_head_of_family', true)
    ->count();

if ($existingHeads > 1) {
    return response()->json(['error' => 'Only one Head of Household per Family Card'], 422);
}
```

### 7.2 Family Member Addition

```php
// Only KK head, RT, or Sekretaris RT can add family members
$familyCard = FamilyCard::findOrFail($familyCardId);

$canAdd = auth()->user()->id === $familyCard->headOfFamily->user_id ||
          in_array(auth()->user()->role, ['ketua_rt', 'sekretaris_rt']);

if (!$canAdd) {
    abort(403, 'Not authorized to add family members');
}
```

### 7.3 Letter Request Validation

```php
// Only Warga role can submit letters
$this->authorize('create', LetterRequest::class);

// Must have active Warga profile
if (!auth()->user()->wargaProfile?->is_active) {
    return response()->json(['error' => 'Warga profile not active'], 422);
}

// Cannot submit same letter type within 30 days
$recentLetter = LetterRequest::where('warga_profile_id', $profile->id)
    ->where('letter_type', $type)
    ->where('created_at', '>', now()->subDays(30))
    ->first();

if ($recentLetter) {
    return response()->json(['error' => 'Letter request recently submitted'], 422);
}
```

---

## 8. Database Indexing Strategy

### Performance-Critical Queries

```sql
-- Letter queue lookup by RT
SELECT * FROM letter_requests 
WHERE rt_id = ? AND status IN ('pending_rt', 'approved_by_rt')
ORDER BY request_date DESC;

-- Index: idx_rt_id, idx_status, idx_request_date

-- Financial transaction lookup by scope
SELECT * FROM financial_transactions 
WHERE scope = 'rt' AND rt_id = ? AND transaction_date BETWEEN ? AND ?
ORDER BY transaction_date DESC;

-- Index: idx_scope, idx_rt_id, idx_date

-- Warga profile lookup by RT
SELECT * FROM warga_profiles 
WHERE rt_id = ? AND is_active = true
ORDER BY nama_lengkap ASC;

-- Index: idx_rt_id, idx_is_active

-- Cross-RT search
SELECT id, nik, nama_lengkap, rt_id FROM warga_profiles 
WHERE rw_id = ? AND (nik LIKE ? OR nama_lengkap LIKE ?)
LIMIT 50;

-- Index: idx_rw_id (partial scan acceptable for small result set)
```

---

## 9. Deployment Checklist

- [ ] Create `.env` with `APP_KEY` (used for HMAC signing)
- [ ] Run database migrations in order
- [ ] Seed default roles via migration
- [ ] Configure Laravel Sanctum for API authentication
- [ ] Set up QR code library: `composer require simplesoftwareio/simple-qr-code`
- [ ] Configure PDF generator: `composer require barryvdh/laravel-dompdf`
- [ ] Run `php artisan queue:work` for async PDF generation
- [ ] Set up cron job: `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`
- [ ] Enable HTTPS in production
- [ ] Configure CORS for frontend
- [ ] Set up logging aggregation (ELK/Splunk)
- [ ] Test letter number generation concurrency (load test)

---

## 10. Testing Strategy

### Unit Tests
- Role assignment (single role constraint)
- Letter number generation format
- QR signature verification
- Financial scope isolation

### Integration Tests
- Warga profile access policies
- Letter workflow state transitions
- Cross-RT search visibility
- Concurrent letter approvals

### Load Tests
- Letter approval under 100 concurrent requests
- Query performance on 100k+ warga profiles
- Financial report generation time

---

## 11. Future Enhancements

1. **Audit Logging:** Log all sensitive operations (role changes, letter approvals, financial edits)
2. **Email Notifications:** Notify warga when letter status changes
3. **SMS Gateway:** SMS notifications for letter approval
4. **Bulk Payment Import:** CSV import for dues payments
5. **Mobile App:** React Native client for citizens
6. **Digital Signature:** e-Signature integration (not just QR codes)
7. **Data Export:** Excel reports for financial audit
