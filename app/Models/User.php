<?php

namespace App\Models;

use App\Models\Employee\ModelHasRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class
User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;
    protected static $logFillable = true;
    protected $table = 'users';
    public const CLIENT_SERVICE = "stu_ClientCabinet";
    public const DRIVER_SERVICE = "stu_DriverService";
    public const TU_PHONE = "tu_Phone";

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'first_name',
        'last_name',
        'patronymic',
        'login',
        'email',
        'password',
        'role',
        'phone',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // public function model_has_roles(){
    //     return $this->belongsTo(ModelHasRole::class, 'id', 'model_id');
    // }

    public function findForPassport($login)
    {
        return $this->where('login', $login)->first();
    }
    // public function sections()
    // {
    //     return $this->belongsToMany(Section::class);
    // }

    // public function subsections()
    // {
    //     return $this->belongsToMany(Subsection::class);
    // }
    // public function employeeDivision()
    // {
    //     return $this->hasMany(EmployeeDivision::class, 'employee_id', 'id')->with('division');
    // }
    // public function client() {
    //     return $this->hasOne(Client::class, 'user_id', 'id');
    // }
    // public function tariffs()
    // {
    //     return $this->belongsToMany(Tariff::class, 'user_tariffs');
    // }



}
