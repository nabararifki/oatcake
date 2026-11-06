<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'name',
        'username',
        'email',
        'password',
        'role',
        'google_id',
        'avatar_url',
        'is_active',
        'theme',
        'locale',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'theme' => 'system',
        'locale' => 'id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the company that the user belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employee profile associated with the user.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Determine if the user has the super_admin role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Determine if the user has the super_user role.
     */
    public function isSuperUser(): bool
    {
        return $this->role === 'super_user';
    }

    /**
     * Determine if the user has the admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Determine if the user has the manager role.
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Determine if the user has the user role.
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Determine if the user holds an administrative role (admin, super_user, super_admin).
     */
    public function isAdminOrAbove(): bool
    {
        return in_array($this->role, ['super_admin', 'super_user', 'admin'], true);
    }

    /**
     * Determine if the user holds a managerial or administrative role.
     */
    public function isManagerOrAbove(): bool
    {
        return in_array($this->role, ['super_admin', 'super_user', 'admin', 'manager'], true);
    }
}
