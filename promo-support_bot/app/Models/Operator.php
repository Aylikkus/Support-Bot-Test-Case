<?php

namespace App\Models;

use Database\Factories\OperatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $operator_id
 * @property string $name
 * @property string $password
 */
class Operator extends Authenticatable
{
    /** @use HasFactory<OperatorFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'operator_id';

    protected $fillable = ['name', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
