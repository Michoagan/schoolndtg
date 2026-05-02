<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Professeur extends Authenticatable
{
    use HasFactory, \Laravel\Sanctum\HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'last_name',
        'first_name',
        'gender',
        'birth_date',
        'email',
        'phone',
        'matiere_id',
        'matiere',
        'photo',
        'personal_code',
        'is_active',
    ];

    protected $hidden = [
        'personal_code',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Route notifications for the mail channel.
     */
    public function routeNotificationForMail($notification)
    {
        return $this->email;
    }

    /**
     * Génère un code personnel unique
     */
    public static function generatePersonalCode()
    {
        do {
            $code = 'PROF'.str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (self::where('personal_code', $code)->exists());

        return $code;
    }

    /**
     * Get the user's full name.
     */
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the photo URL.
     */
    public function getPhotoUrlAttribute()
    {
        if (!$this->photo) return asset('images/default-avatar.png');
        if (str_starts_with($this->photo, 'http')) return $this->photo;
        return asset('storage/professeurs/'.$this->photo);
    }

    public function classesPrincipales(): HasMany
    {
        return $this->hasMany(Classe::class, 'professeur_principal_id');
    }

    /**
     * Get the matiere taught by this professor.
     */
    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * Scope a query to only include active professors.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the full name of the professor.
     */
    public function classes()
    {
        return $this->belongsToMany(Classe::class, 'classe_professeur');
    }

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function cahierTextes()
    {
        return $this->hasMany(CahierTexte::class);
    }
}
