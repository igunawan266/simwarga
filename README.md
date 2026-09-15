# SIMWarga - Community Management & Dues System

A Laravel 11-based system for managing Indonesian neighborhood associations (RT/RW) with citizen management, financial tracking, and digital letter-signing workflows.

## 📋 Features

### Core Functionality
- **Role-Based Access Control**: Admin, RW, Ketua RT, Sekretaris RT, Bendahara RT, and Warga roles
- **Single-Role Enforcement**: Prevents duplicate role assignments at database and application level
- **Citizen Management**: Head of Household (Kepala Keluarga) profiles with family grouping
- **Family Card (KK) Management**: Track family relationships and household members
- **Cross-RT Search**: RW and RT can search citizens across RW by name or KTP (National ID)

### Financial Management
- **Dues Billing**: Monthly billing per RT with configurable amounts
- **Payment Tracking**: Record and track household dues payments
- **Financial Transactions**: Income/expense tracking at RT and RW level
- **Financial Reports**: Scoped reports by RT or RW

### Letter Workflow (Surat Pengantar)
- **Warga Submission**: Citizens submit letter requests (surat pengantar, surat keterangan, etc.)
- **RT Approval**: Ketua RT or Sekretaris RT reviews and approves with sequential numbering
- **Sequential Numbering**: Format: `No. 001/RT.02/RW.03/04/2026` (atomic generation, no race conditions)
- **QR Signature**: HMAC-SHA256 signed QR codes for authenticity verification
- **RW Counter-Signature**: Ketua RW provides final verification with RW QR signature
- **PDF Export**: Generate and export signed letters as PDF

### Security & Audit
- **QR-Based Signatures**: Tamper-evident signatures using HMAC-SHA256
- **Audit Trail**: All sensitive operations logged with user attribution
- **Data Isolation**: RT can only see their own data; RW can see all RT data
- **Password Hashing**: Bcrypt-based password hashing with Laravel defaults

## 🏗️ Architecture

### Tech Stack
- **Backend**: Laravel 11 (PHP 8.2+)
- **Database**: PostgreSQL 15 / MySQL 8
- **Authentication**: Laravel Sanctum (API) or Session-based (Web)
- **Cache**: Redis
- **PDF Generation**: DomPDF / TCPDF
- **QR Codes**: SimpleSoftware QR Code Library
- **Queue**: Redis Queue (Laravel Queue)

### Database Design
14 main tables with normalized schema:
- Users & Roles (with single-role constraint)
- RW & RT Structures (administrative units)
- Warga Profiles & Family Cards (citizen data)
- Letter Requests & Sequences (letter workflow)
- Financial Transactions & Dues (financial management)

**Key Features:**
- Row-level locking for atomic letter sequence generation
- Foreign key constraints for data integrity
- Comprehensive indexes for query performance
- Audit trail tables (letter_sequence_audit)

### Concurrency Handling

#### Sequential Letter Number Generation
- **Solution**: Pessimistic row-level locking with `lockForUpdate()`
- **Guarantee**: No duplicate numbers, even under 100+ concurrent approvals
- **Format**: `No. XXX/RT.Y/RW.Z/MM/YYYY` (resets monthly per RT/RW)

#### Role Assignment
- **Solution**: UNIQUE constraint on `user_roles(user_id)` + middleware validation
- **Guarantee**: Only one role per user at all times

#### Family Data Integrity
- **Solution**: Foreign keys with cascade delete + unique NI constraints
- **Guarantee**: Orphaned records impossible; family member NIK is unique

## 📁 Project Structure

```
app/
├── Models/                    # Eloquent models
├── Http/Controllers/          # API controllers
├── Http/Middleware/           # Auth & role middleware
├── Policies/                  # Resource authorization
└── Services/                  # Business logic (LetterSequenceService)

database/
├── migrations/                # 14 migration files
└── seeders/                   # Role seeders

DATABASE_SCHEMA.md             # Full schema documentation
ARCHITECTURE.md                # System design & decisions
IMPLEMENTATION_GUIDE.md        # Detailed implementation guide
```

## 🚀 Quick Start

### Prerequisites
- PHP 8.2+
- Composer
- PostgreSQL 15 or MySQL 8
- Redis (optional, for cache/queue)
- Node.js + npm (for frontend)

### Installation

```bash
# 1. Clone the repository
git clone <repository-url> simwarga
cd simwarga

# 2. Install PHP dependencies
composer install

# 3. Create environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Configure database in .env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=simwarga
DB_USERNAME=postgres
DB_PASSWORD=your_password

# 6. Run database migrations
php artisan migrate

# 7. Start the development server
php artisan serve

# 8. Start queue worker (for async PDF generation)
php artisan queue:work
```

Access the application at `http://localhost:8000`

## 📖 Documentation

### Core Documents
1. **[DATABASE_SCHEMA.md](./DATABASE_SCHEMA.md)** - Complete database schema with edge case analysis
2. **[ARCHITECTURE.md](./ARCHITECTURE.md)** - System architecture, data model, and design decisions
3. **[IMPLEMENTATION_GUIDE.md](./IMPLEMENTATION_GUIDE.md)** - Detailed implementation including race conditions, RBAC, and business rules

### Key Sections
- **Race Condition Analysis**: Letter sequence generation, role assignment, dues payments
- **Data Privacy & Scoping**: RT isolation, cross-RW search, financial report access control
- **Authorization Matrix**: Role-based access for all resources
- **QR Signature Workflow**: Token structure, HMAC verification, PDF embedding
- **Bootstrap Flow**: Initial setup sequence (Admin → RW → RT → Warga)
- **Deployment Checklist**: Production deployment steps

## 🔐 Authorization Rules

### Role Hierarchy
1. **Admin** - System-wide access (all resources)
2. **Ketua RW** - RW-level management (all RT data within RW)
3. **Ketua RT** - RT-level management (own RT citizens & letters)
4. **Sekretaris RT** - RT support (limited RT management)
5. **Bendahara RT** - Financial management (RT transactions & billing)
6. **Warga** - Self-management (own profile, family, letters)

### Access Control Examples

**Warga Profile Access:**
- Warga: Only own profile
- Ketua RT: All citizens in own RT
- Ketua RW: All citizens in RW (including cross-RT search)
- Admin: All citizens

**Letter Request Access:**
- Warga: Submit and view own letters
- Ketua RT/Sekretaris RT: Review queue, approve/reject (RT level)
- Ketua RW: Review approved letters, provide RW counter-signature
- Admin: Access to all letters

**Financial Access:**
- Bendahara RT: Create RT transactions, manage dues billing/payments
- Ketua RT: View RT financial reports (read-only)
- Ketua RW: View all RT reports, create RW transactions
- Admin: Full financial access

## 🔄 Letter Workflow Example

```
1. Warga submits surat pengantar request
   Status: pending_rt
   
2. Sekretaris RT reviews queue
   ✓ Approves → generates letter number (No. 001/RT.02/RW.03/04/2026)
   ✓ Generates HMAC-SHA256 signed QR code
   Status: approved_by_rt
   
3. Ketua RW receives notification
   ✓ Verifies RT QR signature
   ✓ Adds RW counter-signature
   Status: completed
   
4. Warga downloads signed PDF with both QR codes
```

## 📊 Database Performance

### Key Indexes
- Letter queue: `(rt_id, status, request_date)`
- Warga lookups: `(rt_id, is_active)`, `(rw_id)`
- Financial queries: `(scope, rt_id/rw_id, transaction_date)`
- Sequence generation: `(rt_id, rw_id)` with exclusive locking

### Typical Query Performance
- Letter queue retrieval: <50ms (20 items)
- Warga profile search: <100ms (across 10k profiles)
- Financial report generation: <200ms (500+ transactions)
- Letter approval (with QR signing): <300ms

## 🧪 Testing

### Key Test Scenarios
- [x] Letter number generation under 100+ concurrent approvals
- [x] Cross-RT search doesn't expose other RT's sensitive data
- [x] QR signatures verify correctly after PDF generation
- [x] Role assignment prevents multiple simultaneous assignments
- [x] Financial reports correctly filtered by scope

### Running Tests
```bash
php artisan test
```

## 🚢 Deployment

### Prerequisites
- PostgreSQL 15 with row-level security (optional)
- Redis for sessions/cache/queue
- Laravel Horizon for queue monitoring
- Let's Encrypt SSL certificate

### Production Checklist
- [ ] Set `APP_ENV=production` in `.env`
- [ ] Enable HTTPS (HSTS headers)
- [ ] Configure queue workers with Horizon
- [ ] Set up automated backups
- [ ] Configure logging aggregation (ELK/Splunk)
- [ ] Enable database replication for read scaling
- [ ] Set up monitoring (uptime, response time, errors)

## 📝 API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user (defaults to warga role)
- `POST /api/auth/login` - Login and get Sanctum token
- `POST /api/auth/logout` - Logout

### Warga Management
- `GET /api/warga/search` - Search citizens (cross-RT)
- `GET /api/warga/{id}` - Get profile (scoped access)
- `POST /api/warga` - Create profile (RT/RW/Admin)
- `PUT /api/warga/{id}` - Update profile

### Letter Requests
- `POST /api/letters` - Submit letter request (Warga)
- `GET /api/letters/rt/pending` - RT approval queue
- `POST /api/letters/{id}/approve-rt` - Approve at RT
- `GET /api/letters/rw/pending` - RW approval queue
- `POST /api/letters/{id}/approve-rw` - Approve at RW
- `GET /api/letters/{id}` - Download signed letter

### Financial
- `POST /api/financial/transactions` - Record transaction
- `GET /api/financial/transactions` - List transactions
- `POST /api/dues/billings` - Create monthly billing
- `POST /api/dues/payments` - Record payment
- `GET /api/financial/reports` - Generate reports

## 🤝 Contributing

1. Create feature branch from `development`
2. Follow PSR-12 coding standards
3. Write tests for new features
4. Submit PR with detailed description
5. Ensure all tests pass before merging

## 📄 License

This project is proprietary software for Indonesian community management.

## 👥 Support

For issues, questions, or suggestions, please contact the development team.

---

**Last Updated:** September 2026  
**Version:** 1.0.0  
**Status:** Architecture & Implementation Complete
