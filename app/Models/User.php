<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'phone', 'password', 'google_id', 'apple_id',
    'gender', 'interested_in', 'birthdate', 'height_cm', 'occupation',
    'education', 'bio', 'relationship_goal', 'voice_intro_url',
    'city_id', 'latitude', 'longitude',
    'is_verified', 'verification_status',
    'is_premium', 'premium_until', 'incognito', 'travel_mode', 'boost_until',
    'show_in_likes', 'show_in_anon', 'public_key', 'onboarding_completed_at', 'last_active_at',
    'notify_matches', 'notify_messages', 'notify_likes', 'notify_anon', 'quiet_hours',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'birthdate' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
            'password' => 'hashed',
            'is_verified' => 'boolean',
            'is_premium' => 'boolean',
            'incognito' => 'boolean',
            'travel_mode' => 'boolean',
            'show_in_likes' => 'boolean',
            'show_in_anon' => 'boolean',
            'notify_matches' => 'boolean',
            'notify_messages' => 'boolean',
            'notify_likes' => 'boolean',
            'notify_anon' => 'boolean',
            'quiet_hours' => 'boolean',
            'premium_until' => 'datetime',
            'boost_until' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    /** @return HasMany<Photo> */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /** @return HasMany<Verification> */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /** @return HasOne<Preference> */
    public function preference(): HasOne
    {
        return $this->hasOne(Preference::class);
    }

    /** @return BelongsTo<City, User> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsToMany<Interest> */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'user_interests');
    }

    /** @return BelongsToMany<Language> */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'user_languages');
    }

    /** @return BelongsToMany<LifestyleOption> */
    public function lifestyleOptions(): BelongsToMany
    {
        return $this->belongsToMany(LifestyleOption::class, 'user_lifestyle');
    }
}
