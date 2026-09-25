<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    // Не "roles" — та таблица принадлежит Spatie\Permission (система прав
    // менеджеров/Position), эта модель к ней отношения не имеет.
    protected $table = 'app_roles';

    protected $fillable = [
        'name',
        'description',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
