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

    /**
     * THE ROLE VOCABULARY — one definition, used everywhere.
     *
     * `users.role` is a MySQL enum of exactly four strings:
     * customer, staff, admin, supervisor. There is no roles table and no
     * permission package; these constants are the closest thing this app has
     * to one, and every role SET in the codebase must be spelled from here
     * rather than as another inline array literal. The Sept 2026 supervisor
     * pass exists partly because the branch lock HAD drifted into a second,
     * hand-rolled copy — four `role === 'staff'` comparisons in the
     * QR-generator section of AdminController — which a new branch-locked role
     * would have sailed straight past, unlocked.
     *
     * WHAT EACH ROLE MEANS
     * --------------------
     *  customer   — the storefront. Never touches the admin guard at all.
     *  staff      — counter shift work, locked to one branch.
     *  supervisor — the MANAGER tier: everything staff can do, plus the
     *               management surface the permission matrix marks Y for a
     *               manager — voucher authoring, analytics, ads, the
     *               catalogue, stock movement and staff-account management —
     *               still locked to one branch. The foundation pass gave it
     *               the staff surface only and left the widening to this one;
     *               MANAGER_ROLES below is where that widening is defined.
     *               What a supervisor still cannot do is anything DESTRUCTIVE
     *               at the business level (delete a voucher, delete inventory)
     *               or anything about the shape of the business itself
     *               (branches, roles, system configuration).
     *  admin      — the OWNER. Not branch-locked; sees and acts on every
     *               branch, and is the only role that may create/edit branches,
     *               manage portal accounts, or change system configuration.
     *               There is no separate "owner" role in this system: admin IS
     *               the owner, which is why
     *               AdminAuthController::resettableRoles() covers admin alone.
     */

    /** Roles that may authenticate through the `admin` guard at all. */
    public const PORTAL_ROLES = ['admin', 'staff', 'supervisor'];

    /**
     * Roles confined to a single branch — the ones AdminOrderAccess locks.
     *
     * `admin` is deliberately absent: an admin is unrestricted and gets the
     * "Viewing:" branch picker instead. Adding a role here is all it takes to
     * branch-lock it across the lists, the per-record endpoints and the QR
     * section, because all three ask AdminOrderAccess::lockedBranchId().
     */
    public const BRANCH_LOCKED_ROLES = ['staff', 'supervisor'];

    /**
     * Roles an admin may create, deactivate or set a password for on
     * /admin/users.
     *
     * `admin` is deliberately absent, and must stay absent: without that, one
     * admin could silently take over another admin's account, and a mistyped
     * id could lock the owner out of their own system. This is the constant
     * behind the `role !== 'staff'` guards that updateStaffPassword() and
     * toggleUser() have always carried.
     */
    public const ADMIN_MANAGEABLE_ROLES = ['staff', 'supervisor'];

    /**
     * Roles carrying MANAGEMENT authority — the "Y | Y | N" half of the
     * permission matrix.
     *
     * Every feature the Sept 2026 matrix marks Y for the owner AND Y for a
     * manager/supervisor but N for staff is gated on membership of this set:
     * voucher authoring, analytics, advertisements, portal account
     * management, the catalogue (menu items, categories, options, add-ons)
     * and stock movement.
     *
     * This is the SAME kind of constant as BRANCH_LOCKED_ROLES above and is
     * used the same way — route groups spell `role:admin,supervisor` from it,
     * blades ask isManager(), and a controller needing a per-row rule asks
     * isManager() plus a branch check. There is deliberately no second
     * permission table, no Gate and no policy class: this matrix is expressed
     * as role SETS on this model, which is exactly what the constants above
     * already were and what the supervisor foundation pass established.
     *
     * `admin` is first and is a member because the owner has every management
     * capability a supervisor has and more. Anything the OWNER ALONE may do —
     * deleting a voucher, deleting inventory, branch CRUD, system config,
     * changing a role — is gated on isAdmin() / `role:admin`, never on this.
     */
    public const MANAGER_ROLES = ['admin', 'supervisor'];

    /**
     * Roles a MANAGER (as opposed to the owner) may act on in staff
     * management.
     *
     * A supervisor may edit, reset the password for, activate, deactivate or
     * delete ONLY a plain `staff` account — and only one in their own branch;
     * the branch half is checked separately against
     * AdminOrderAccess::lockedBranchId(). See canManageAccount().
     *
     * `supervisor` is deliberately absent and must stay absent, for exactly
     * the reason `admin` is absent from ADMIN_MANAGEABLE_ROLES: without that,
     * one supervisor could reset a peer supervisor's password and take over
     * their account — the same takeover the owner-account guard already
     * refuses one tier up.
     */
    public const MANAGER_MANAGEABLE_ROLES = ['staff'];

    /**
     * Mass assignable attributes
     * (Yung mga columns na pwede mag-fill via User::create([...]))
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'contact_number',
        'address',
        'profile_image',
        'branch_id',
        'is_active',
        'verification_code',
        'verified_at',
        'points',
        'pwd_card_number',
        'pwd_name',
        'pwd_image',
        'senior_card_number',
        'senior_name',
        'senior_image',
    ];

    /**
     * Hidden attributes (hindi makikita pag mag-display ng user)
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',  // Auto-encrypt yung password
    ];

    /**
     * RELATIONSHIPS
     */

    // User belongs to a branch
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // User has many orders (customer side)
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // User has many discount cards (PWD/Senior IDs)
    public function discountCards(): HasMany
    {
        return $this->hasMany(DiscountCard::class);
    }

    // User has many vouchers
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * HELPER METHODS — para easy check ng role
     */

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * Is this account confined to a single branch?
     *
     * True for staff and supervisor, false for admin (and for a customer, who
     * never reaches the admin guard). The authoritative branch VALUE still
     * comes from AdminOrderAccess::lockedBranchId() — this answers only the
     * yes/no, so a caller that needs the branch id cannot accidentally read a
     * null branch_id as "unrestricted".
     */
    public function isBranchLocked(): bool
    {
        return in_array($this->role, self::BRANCH_LOCKED_ROLES, true);
    }

    /**
     * Does this account carry management authority?
     *
     * True for the owner (admin) and for a supervisor, false for staff. This
     * is the yes/no behind every "Y | Y | N" row of the permission matrix, and
     * it is what the admin blades ask instead of the `role === 'admin'`
     * comparison they used to carry. Left as that comparison, a supervisor
     * would have been shown a portal with every management control hidden
     * while the routes behind those controls accepted them perfectly well —
     * the mirror image of the drift the branch lock hit.
     *
     * It answers AUTHORITY only, never SCOPE. A supervisor is still branch
     * locked (isBranchLocked() is true for them), and every per-row rule —
     * which staff account, which menu item — additionally consults
     * AdminOrderAccess. Never read a true here as "may act on this record".
     */
    public function isManager(): bool
    {
        return in_array($this->role, self::MANAGER_ROLES, true);
    }

    /**
     * May this account act on $target in staff management?
     *
     * The single definition of the matrix's LIMITED entry for "Delete Staff
     * Account", reused by edit, password-reset and activate/deactivate as well
     * so the four endpoints cannot drift apart:
     *
     *  - the OWNER may act on any ADMIN_MANAGEABLE_ROLES account (staff or
     *    supervisor) in any branch, and on no admin — so never a peer owner.
     *  - a SUPERVISOR may act only on a MANAGER_MANAGEABLE_ROLES account
     *    (staff alone) assigned to their OWN branch. Not a peer supervisor,
     *    not the owner, not another branch's staff — including when the id of
     *    that other branch's staff row is supplied directly in the URL.
     *  - STAFF may act on nobody, and never reach these routes at all.
     *
     * Acting on YOURSELF is refused for everyone: deactivating or deleting the
     * account you are signed in as is a self-lockout, not a permission.
     */
    public function canManageAccount(?self $target): bool
    {
        if (! $target || ! $this->isManager()) {
            return false;
        }

        // Never yourself.
        if ((int) $target->id === (int) $this->id) {
            return false;
        }

        if ($this->isAdmin()) {
            return in_array($target->role, self::ADMIN_MANAGEABLE_ROLES, true);
        }

        // Supervisor — the target's ROLE and BRANCH must both match.
        if (! in_array($target->role, self::MANAGER_MANAGEABLE_ROLES, true)) {
            return false;
        }

        $myBranch = \App\Services\AdminOrderAccess::lockedBranchId();

        /*
         * A manager with no resolvable branch manages nobody.
         *
         * lockedBranchId() already fails closed for a branchless supervisor by
         * returning 0, which no real branch_id can equal, so the comparison
         * below would refuse anyway. The explicit guard is here so the refusal
         * is stated rather than resting on "0 never matches" — and so a target
         * row with a NULL branch_id (which would otherwise compare as 0 after
         * the int cast) is refused outright rather than accidentally matching
         * that same sentinel.
         */
        if ($myBranch === null || $target->branch_id === null) {
            return false;
        }

        return (int) $target->branch_id === $myBranch;
    }
}
