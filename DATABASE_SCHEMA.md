# SIMWarga Database Architecture & Schema

## Architecture Validation & Edge Cases

### 1. Sequential Letter Number Generation (Race Condition Analysis)
**Problem:** Multiple RT officers might generate letters simultaneously, causing duplicate sequence numbers.
**Solution:** 
- Use database transactions with row-level locking
- Store `last_sequence_number` in `letter_sequences` table with RT/RW scope
- Use `FOR UPDATE` with pessimistic locking during increment
- Alternative: Use database triggers or stored procedures for atomic increment

### 2. Role Assignment Constraints
**Constraint:** Single role per user (no dual roles)
- Enforce at DB level: unique constraint on `(user_id, role)` in a junction table
- Add application-level validation middleware
- Transaction rollback if role violates the constraint

### 3. Data Scope & Privacy (Cross-RT Search)
**Rules:**
- RT can only see full profiles of their own RT citizens
- RT can search across RW by name/KTP but cannot view full profiles
- Use database queries with `rt_id` filtering vs `rw_id` filtering
- Implement separate query scopes in models

### 4. Financial Report Isolation
**Scopes:** RT financial reports (scope='rt') vs RW financial reports (scope='rw')
- Add scope-based access control middleware
- RW can view all RT reports within their RW
- RT can only modify their own reports

---

## Complete Database Schema

### Table: `users`
Core authentication and role assignment.
```sql
CREATE TABLE users (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    email_verified_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_is_active (is_active)
);
```

### Table: `roles`
Centralized role definitions.
```sql
CREATE TABLE roles (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default roles
INSERT INTO roles (name, display_name) VALUES
('warga', 'Warga'),
('admin', 'System Administrator'),
('rw', 'Ketua RW'),
('ketua_rt', 'Ketua RT'),
('sekretaris_rt', 'Sekretaris RT'),
('bendahara_rt', 'Bendahara RT');
```

### Table: `user_roles`
Junction table enforcing single-role constraint.
```sql
CREATE TABLE user_roles (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by BIGINT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_user_role (user_id, role_id),
    INDEX idx_role_id (role_id)
);
```

### Table: `rw_structures` (RW Administrative Unit)
Represents a Rukun Warga (Neighborhood Association).
```sql
CREATE TABLE rw_structures (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    rw_number INT NOT NULL UNIQUE,
    name VARCHAR(100),
    kelurahan VARCHAR(100),
    kecamatan VARCHAR(100),
    kota VARCHAR(100),
    provinsi VARCHAR(100),
    ketua_rw_user_id BIGINT UNSIGNED,
    total_rt_units INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ketua_rw_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rw_number (rw_number),
    INDEX idx_ketua_rw (ketua_rw_user_id)
);
```

### Table: `rt_structures` (RT Administrative Unit)
Represents a Rukun Tetangga (Block Association).
```sql
CREATE TABLE rt_structures (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    rw_id BIGINT UNSIGNED NOT NULL,
    rt_number INT NOT NULL,
    name VARCHAR(100),
    ketua_rt_user_id BIGINT UNSIGNED,
    sekretaris_rt_user_id BIGINT UNSIGNED,
    bendahara_rt_user_id BIGINT UNSIGNED,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (ketua_rt_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (sekretaris_rt_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (bendahara_rt_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_rt_per_rw (rw_id, rt_number),
    INDEX idx_rw_id (rw_id),
    INDEX idx_ketua_rt (ketua_rt_user_id)
);
```

### Table: `warga_profiles`
Citizen/Head of Household profiles.
```sql
CREATE TABLE warga_profiles (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    nik VARCHAR(20) UNIQUE NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    tempat_lahir VARCHAR(100),
    tanggal_lahir DATE,
    jenis_kelamin ENUM('L', 'P'),
    alamat_jalan VARCHAR(255),
    nomor_rumah VARCHAR(20),
    rt_id BIGINT UNSIGNED NOT NULL,
    rw_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(20),
    status_dalam_keluarga VARCHAR(50) DEFAULT 'Kepala Keluarga',
    is_head_of_family BOOLEAN DEFAULT TRUE,
    no_kk VARCHAR(20),
    status_perkawinan VARCHAR(50),
    pekerjaan VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE RESTRICT,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_nik (nik),
    INDEX idx_rt_id (rt_id),
    INDEX idx_rw_id (rw_id),
    INDEX idx_is_head (is_head_of_family),
    INDEX idx_nik (nik)
);
```

### Table: `family_cards` (KK / Family Grouping)
Groups family members under a Head of Household.
```sql
CREATE TABLE family_cards (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    head_of_family_id BIGINT UNSIGNED NOT NULL,
    no_kk VARCHAR(20) UNIQUE NOT NULL,
    alamat_jalan VARCHAR(255),
    nomor_rumah VARCHAR(20),
    rt_id BIGINT UNSIGNED NOT NULL,
    rw_id BIGINT UNSIGNED NOT NULL,
    tanggal_cetak DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (head_of_family_id) REFERENCES warga_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE RESTRICT,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_no_kk (no_kk),
    INDEX idx_head_of_family (head_of_family_id),
    INDEX idx_rt_id (rt_id)
);
```

### Table: `family_members`
Family members under a Family Card (KK).
```sql
CREATE TABLE family_members (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    family_card_id BIGINT UNSIGNED NOT NULL,
    warga_profile_id BIGINT UNSIGNED,
    nik VARCHAR(20) UNIQUE,
    nama_lengkap VARCHAR(100) NOT NULL,
    hubungan_keluarga VARCHAR(50),
    jenis_kelamin ENUM('L', 'P'),
    tanggal_lahir DATE,
    tempat_lahir VARCHAR(100),
    status_perkawinan VARCHAR(50),
    pekerjaan VARCHAR(100),
    pendidikan VARCHAR(50),
    status_dalam_keluarga VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (family_card_id) REFERENCES family_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (warga_profile_id) REFERENCES warga_profiles(id) ON DELETE SET NULL,
    UNIQUE KEY uq_nik (nik),
    INDEX idx_family_card (family_card_id),
    INDEX idx_hubungan_keluarga (hubungan_keluarga)
);
```

### Table: `financial_transactions`
RT and RW income/expense tracking.
```sql
CREATE TABLE financial_transactions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    scope ENUM('rt', 'rw') NOT NULL,
    rt_id BIGINT UNSIGNED,
    rw_id BIGINT UNSIGNED NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    description TEXT,
    transaction_date DATE NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_scope (scope),
    INDEX idx_rt_id (rt_id),
    INDEX idx_rw_id (rw_id),
    INDEX idx_type (type),
    INDEX idx_date (transaction_date),
    CHECK (scope = 'rt' AND rt_id IS NOT NULL OR scope = 'rw' AND rt_id IS NULL)
);
```

### Table: `dues_billings`
Monthly mandatory dues billing.
```sql
CREATE TABLE dues_billings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    rt_id BIGINT UNSIGNED NOT NULL,
    rw_id BIGINT UNSIGNED NOT NULL,
    billing_month INT NOT NULL,
    billing_year INT NOT NULL,
    amount_per_house DECIMAL(15, 2) NOT NULL,
    due_date DATE,
    status ENUM('draft', 'active', 'closed') DEFAULT 'draft',
    description TEXT,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_billing_month_year (rt_id, billing_month, billing_year),
    INDEX idx_rt_id (rt_id),
    INDEX idx_status (status),
    INDEX idx_date (billing_month, billing_year)
);
```

### Table: `dues_payments`
Payment records for dues.
```sql
CREATE TABLE dues_payments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    billing_id BIGINT UNSIGNED NOT NULL,
    warga_profile_id BIGINT UNSIGNED NOT NULL,
    paid_amount DECIMAL(15, 2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50),
    status ENUM('pending', 'paid', 'partial', 'overdue') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_id) REFERENCES dues_billings(id) ON DELETE CASCADE,
    FOREIGN KEY (warga_profile_id) REFERENCES warga_profiles(id) ON DELETE CASCADE,
    UNIQUE KEY uq_billing_warga (billing_id, warga_profile_id),
    INDEX idx_warga_profile (warga_profile_id),
    INDEX idx_status (status),
    INDEX idx_payment_date (payment_date)
);
```

### Table: `letter_sequences`
Stores the last sequence number per RT/RW for atomic increment.
```sql
CREATE TABLE letter_sequences (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    rt_id BIGINT UNSIGNED,
    rw_id BIGINT UNSIGNED,
    last_sequence_number INT DEFAULT 0,
    last_sequence_month INT,
    last_sequence_year INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rt_rw (rt_id, rw_id),
    INDEX idx_rt_id (rt_id),
    INDEX idx_rw_id (rw_id)
);
```

### Table: `letter_requests` (Surat Pengantar)
Letter request workflow with QR signatures.
```sql
CREATE TABLE letter_requests (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    warga_profile_id BIGINT UNSIGNED NOT NULL,
    rt_id BIGINT UNSIGNED NOT NULL,
    rw_id BIGINT UNSIGNED NOT NULL,
    letter_type VARCHAR(100) NOT NULL,
    purpose TEXT,
    status VARCHAR(50) DEFAULT 'pending_rt',
    letter_number VARCHAR(50),
    request_date DATE NOT NULL,
    rejection_reason TEXT,
    rejected_at TIMESTAMP NULL,
    rejected_by BIGINT UNSIGNED,
    
    -- RT Approval
    approved_by_rt_at TIMESTAMP NULL,
    approved_by_rt_user_id BIGINT UNSIGNED,
    rt_qr_token TEXT,
    rt_signature_hash VARCHAR(255),
    
    -- RW Approval (optional, depends on letter type)
    approved_by_rw_at TIMESTAMP NULL,
    approved_by_rw_user_id BIGINT UNSIGNED,
    rw_qr_token TEXT,
    rw_signature_hash VARCHAR(255),
    
    -- Completion
    completed_at TIMESTAMP NULL,
    pdf_path VARCHAR(255),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (warga_profile_id) REFERENCES warga_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE RESTRICT,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by_rt_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by_rw_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_warga_profile (warga_profile_id),
    INDEX idx_rt_id (rt_id),
    INDEX idx_status (status),
    INDEX idx_letter_number (letter_number),
    INDEX idx_request_date (request_date)
);
```

### Table: `letter_sequence_audit`
Audit trail for letter sequence generation.
```sql
CREATE TABLE letter_sequence_audit (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    letter_request_id BIGINT UNSIGNED NOT NULL,
    rt_id BIGINT UNSIGNED NOT NULL,
    rw_id BIGINT UNSIGNED NOT NULL,
    generated_letter_number VARCHAR(50) NOT NULL,
    sequence_number INT NOT NULL,
    generated_by BIGINT UNSIGNED NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (letter_request_id) REFERENCES letter_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (rt_id) REFERENCES rt_structures(id) ON DELETE RESTRICT,
    FOREIGN KEY (rw_id) REFERENCES rw_structures(id) ON DELETE RESTRICT,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_letter_request (letter_request_id),
    INDEX idx_generated_number (generated_letter_number)
);
```

---

## Indexes Summary for Performance
- User lookups: `users(email)`, `users(is_active)`
- Role checks: `user_roles(role_id)`, `user_roles(user_id)` (UNIQUE)
- RT/RW structure: `rt_structures(rw_id)`, `rw_structures(ketua_rw_user_id)`
- Data scope filtering: `warga_profiles(rt_id)`, `warga_profiles(rw_id)`
- Transaction queries: `financial_transactions(rt_id, type, scope)`, `financial_transactions(date)`
- Letter workflow: `letter_requests(status, rt_id, rw_id)`, `letter_requests(letter_number)`
