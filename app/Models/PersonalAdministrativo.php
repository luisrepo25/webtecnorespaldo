<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalAdministrativo extends Model
{
    protected $table = 'personal_administrativo';
    protected $primaryKey = 'id_personal';
    public $timestamps = false;

    protected $fillable = [
        'id_usuario', 'legajo_personal', 'cargo',
        'fecha_ingreso', 'oficina', 'sueldo_base', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'id_usuario'    => 'integer',
            'fecha_ingreso' => 'date',
            'sueldo_base'   => 'decimal:2',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
