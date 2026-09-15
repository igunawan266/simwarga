# SIMWarga System Architecture

## System Overview

SIMWarga is a Laravel 11-based Community Management & Mandatory Dues (Iuran Warga) system for Indonesian neighborhood associations (RT/RW). It provides role-based citizen management, financial tracking, and digital letter-signing workflows.

## Core Components

### 1. Authentication & Authorization
- **Auth Method:** Laravel Sanctum (API tokens) or Session-based (Web)
- **Role Model:** Single-role-per-user (enforced by DB constraint + middleware)
- **Policy Gates:** Laravel Policy classes for resource-level authorization

**Default Roles:**
- `warga` - Citizen
- `admin` - System administrator
- `rw` - Ketua RW (Neighborhood chief)
- `ketua_rt` - Ketua RT (Block chief)
- `sekretaris_rt` - Secretary RT
- `bendahara_rt` - Treasurer RT

### 2. Data Model

**Administrative Structure:**
```
RwStructure (Rukun Warga)
  ├── RtStructure[1..N] (Rukun Tetangga)
  ├── WargaProfile[1..N] (Citizens)
  │   └── FamilyCard (Kartu Keluarga)
  │       └── FamilyMember[1..N]
  └── FinancialTransaction[0..N]
```

**Letter Workflow:**
```
LetterRequest (Surat Pengantar)
├── Status: pending_rt → approved_by_rt → approved_by_rw → completed
├── QR Signatures: RT approval + RW verification
└── LetterSequenceAudit (Immutable audit trail)
```

**Financial Management:**
```
DuesBilling (monthly)
├── Scope: rt or rw
└── DuesPayment[0..N] (per household)

FinancialTransaction
├── Type: income | expense
├── Scope: rt | rw
└── Category: (based on transaction type)
```

### 3. Concurrency & Race Condition Handling

| Issue | Solution |
|-------|----------|
| Letter sequence duplicates | Pessimistic row-level locking (lockForUpdate) |
| Concurrent role assignments | UNIQUE(user_id) in user_roles table |
| Double-role assignments | Middleware + DB constraints |
| Family member conflicts | Foreign key + unique NI constraints |

**Implementation Pattern:**
```php
// Atomic letter number generation
DB::transaction(function () {
    $sequence = LetterSequence::where('rt_id', $rtId)->lockForUpdate()->first();
    $sequence->increment('last_sequence_number');
    // Number is unique and sequential
});
```

### 4. Data Privacy & Scoping

**RT-Level Isolation:**
- RT officers see ONLY their own RT citizens (full profile)
- Cannot view financial data of other RTs
- Policy: `WargaProfilePolicy::view()` checks `rt_id` match

**Cross-RW Search:**
- RT can search across entire RW by Name or KTP
- Returns ONLY: id, nik, nama_lengkap, rt_id (no sensitive fields)
- Policy: Separate search endpoint with field projection

**RW-Level Visibility:**
- RW can see all RT citizens within their RW
- RW can view all RT financial reports
- Can create RW-level financial summaries

**Admin Override:**
- Admin can access all data
- Audit-logged for compliance

### 5. Letter Workflow & QR Signatures

**State Machine:**
```
Warga submits request (pending_rt)
    ↓ [RT: Ketua RT or Sekretaris RT]
RT Approval (approved_by_rt)
    - Generates sequential number: No. XXX/RT.Y/RW.Z/MM/YYYY
    - Generates HMAC-SHA256 QR signature
    - Stores: letter_number, rt_qr_token, rt_signature_hash
    ↓ [RW: Ketua RW]
RW Verification (completed)
    - Validates RT QR signature
    - Generates RW counter-signature
    - Stores: rw_qr_token, rw_signature_hash
    ↓
Warga downloads signed PDF
```

**QR Token Structure:**
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

**Signature Verification:**
- Uses application secret key (APP_KEY from .env)
- HMAC-SHA256 ensures: authenticity + integrity + non-repudiation
- Verifiable offline (no server needed)
- Scannable QR codes embed full token

### 6. Financial Management

**Dues Billing:**
- Bendahara RT creates monthly billing per RT
- Amount per house, due date configurable
- Status: draft → active → closed

**Dues Payments:**
- Track payments per household per month
- Status: pending | partial | paid | overdue
- Payment date + method recorded
- Unique constraint on (billing_id, warga_profile_id) prevents duplicates

**Financial Transactions:**
- Flexible income/expense tracking
- Scoped: RT-level or RW-level
- Category-based grouping
- All transactions immutable (audit trail)

### 7. Middleware & Guards

**Authentication Checks:**
- `auth:sanctum` - API token verification
- `auth:web` - Session verification

**Authorization Checks:**
- `CheckRole` - Role-based access (middleware)
- `EnforceSingleRole` - Prevents multi-role users (middleware)
- Policy Gates - Resource-level authorization (Policies)

**Example Route Protection:**
```php
Route::middleware(['auth:sanctum', 'check.role:ketua_rt,sekretaris_rt'])->group(function () {
    Route::post('/letters/{letter}/approve-rt', [LetterRequestController::class, 'approveForRT']);
});
```

### 8. Database Schema Highlights

**Key Tables:**
- `users` - Authentication
- `roles` - Role definitions
- `user_roles` - Single-role assignment (UNIQUE user_id)
- `rw_structures` - RW administrative unit
- `rt_structures` - RT administrative unit
- `warga_profiles` - Citizen profiles (Kepala Keluarga)
- `family_cards` - Kartu Keluarga grouping
- `family_members` - Extended family under KK
- `letter_requests` - Letter workflow with QR signatures
- `letter_sequences` - Atomic sequence counter per RT/RW
- `letter_sequence_audit` - Immutable audit trail
- `financial_transactions` - Income/expense tracking
- `dues_billings` - Monthly dues billing
- `dues_payments` - Payment records

**Indexes for Performance:**
- Letter queries: (rt_id, status, request_date)
- Warga lookups: (rt_id, is_active), (rw_id)
- Financial reports: (scope, rt_id/rw_id, transaction_date)
- Sequence generation: (rt_id, rw_id)

### 9. API Endpoints Structure

**Authentication:**
- `POST /api/auth/register` - Warga self-register (default role: warga)
- `POST /api/auth/login` - Login (returns Sanctum token)
- `POST /api/auth/logout` - Logout

**Warga Management:**
- `GET /api/warga/search` - Cross-RT search (name/KTP) - RW/RT only
- `GET /api/warga/{id}` - View profile (scoped access)
- `POST /api/warga` - Create profile (RT/RW/Admin)
- `PUT /api/warga/{id}` - Update profile (RT/RW or self)

**Letter Requests:**
- `POST /api/letters` - Submit letter request (Warga)
- `GET /api/letters/rt/pending` - RT queue (Ketua RT/Sekretaris RT)
- `POST /api/letters/{id}/approve-rt` - Approve at RT (with QR)
- `POST /api/letters/{id}/reject-rt` - Reject at RT
- `GET /api/letters/rw/pending` - RW queue (Ketua RW)
- `POST /api/letters/{id}/approve-rw` - Approve at RW (with counter-signature)
- `GET /api/letters/{id}` - Download signed letter (PDF)

**Financial Management:**
- `POST /api/financial/transactions` - Record transaction (Bendahara RT/RW)
- `GET /api/financial/transactions` - List transactions (scoped)
- `POST /api/dues/billings` - Create monthly billing (Bendahara RT)
- `POST /api/dues/payments` - Record payment (Bendahara RT)
- `GET /api/financial/reports` - Generate reports (scoped)

### 10. Deployment Architecture

**Production Stack:**
```
nginx (reverse proxy)
  ↓
PHP-FPM 8.2 (Laravel app)
  ↓
PostgreSQL 15 (primary DB)
  ↓ (replica for reads)
PostgreSQL 15 (read replica)

Redis (cache/session store)
Queue Worker (Laravel Horizon)
```

**Key Configurations:**
- Database: PostgreSQL with row-level security (optional)
- Cache: Redis (L2 for frequently accessed data)
- Queue: Redis queue for async PDF generation
- File Storage: S3 for letter PDFs (encrypted at rest)
- Logging: ELK stack for audit trails
- HTTPS: Let's Encrypt certificates

### 11. Security Considerations

**Input Validation:**
- All inputs validated against whitelist rules
- No dynamic SQL queries (parameterized only)
- CSRF tokens on all forms (Laravel default)

**Authorization:**
- Every resource access checked against policies
- Row-level authorization (user can only see their data)
- Admin actions logged for audit

**Data Protection:**
- Passwords hashed with bcrypt (Laravel default)
- Sensitive data: encrypted at rest + in transit (HTTPS)
- No plaintext secrets in code (use .env)

**QR Signature Security:**
- Uses HMAC-SHA256 with application secret key
- Prevents forgery without stealing APP_KEY
- Tamper-detection: verification fails if payload altered
- Not PKI-based but sufficient for local government use

### 12. Scalability Considerations

**Horizontal Scaling:**
- Stateless API servers (session in Redis)
- Database read replicas for reports
- Cache layer for frequent queries

**Performance Optimization:**
- Database indexes on query paths
- N+1 prevention: eager load relationships
- Pagination for large result sets (default 20 per page)
- Query optimization: only fetch needed columns

**Monitoring:**
- Response time SLA: <200ms for API endpoints
- Database connection pool monitoring
- Queue job failure alerts
- PDF generation timeout: 30s

---

## File Structure

```
app/
├── Models/
│   ├── User.php
│   ├── Role.php
│   ├── RwStructure.php
│   ├── RtStructure.php
│   ├── WargaProfile.php
│   ├── FamilyCard.php
│   ├── FamilyMember.php
│   ├── LetterRequest.php
│   ├── LetterSequence.php
│   ├── LetterSequenceAudit.php
│   ├── FinancialTransaction.php
│   ├── DuesBilling.php
│   └── DuesPayment.php
├── Http/
│   ├── Controllers/
│   │   ├── LetterRequestController.php
│   │   ├── WargaProfileController.php
│   │   └── FinancialTransactionController.php
│   └── Middleware/
│       ├── CheckRole.php
│       └── EnforceSingleRole.php
├── Policies/
│   ├── WargaProfilePolicy.php
│   ├── LetterRequestPolicy.php
│   └── FinancialTransactionPolicy.php
└── Services/
    └── LetterSequenceService.php

database/
├── migrations/
│   ├── 2024_01_01_000001_create_users_table.php
│   ├── 2024_01_01_000002_create_roles_table.php
│   ├── 2024_01_01_000003_create_user_roles_table.php
│   ├── 2024_01_01_000004_create_rw_structures_table.php
│   ├── 2024_01_01_000005_create_rt_structures_table.php
│   ├── 2024_01_01_000006_create_warga_profiles_table.php
│   ├── 2024_01_01_000007_create_family_cards_table.php
│   ├── 2024_01_01_000008_create_family_members_table.php
│   ├── 2024_01_01_000009_create_financial_transactions_table.php
│   ├── 2024_01_01_000010_create_dues_billings_table.php
│   ├── 2024_01_01_000011_create_dues_payments_table.php
│   ├── 2024_01_01_000012_create_letter_sequences_table.php
│   ├── 2024_01_01_000013_create_letter_requests_table.php
│   └── 2024_01_01_000014_create_letter_sequence_audit_table.php
└── seeders/
    └── RoleSeeder.php

routes/
├── api.php (API routes)
└── web.php (Web routes)
```

---

## Implementation Status

✅ **Completed:**
- Database schema with all tables
- Eloquent models with relationships
- RBAC policies for resource authorization
- Middleware for role checking + single-role enforcement
- LetterSequenceService with atomic generation + QR signing
- LetterRequestController with full workflow
- All model files with proper relationships

⏳ **Next Steps:**
1. Create API routes (routes/api.php)
2. Implement remaining controllers (WargaProfile, Financial, etc.)
3. Create seed migrations for initial roles
4. Build React/Vue frontend
5. Add comprehensive test suite
6. Deploy to staging environment

---

## Quick Start

```bash
# 1. Clone repository
git clone <repo> simwarga
cd simwarga

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Run migrations
php artisan migrate

# 5. Start dev server
php artisan serve

# 6. Access at http://localhost:8000
```

---

## Validation & Testing Checklist

- [ ] Letter number generation with 100+ concurrent approvals
- [ ] Cross-RT search doesn't expose other RT's sensitive data
- [ ] QR signatures verify correctly after PDF generation
- [ ] Role assignment prevents multiple simultaneous assignments
- [ ] Financial reports correctly filtered by scope
- [ ] All API endpoints return proper HTTP status codes
- [ ] Load test: 100 warga profiles loading simultaneously
- [ ] Audit trail complete for all sensitive operations
