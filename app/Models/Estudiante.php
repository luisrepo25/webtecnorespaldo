<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estudiante extends Model
{
    protected $table = 'estudiantes';
    protected $primaryKey = 'id_estudiante';
    public $timestamps = false;

    protected $fillable = [
        'id_usuario', 'legajo', 'fecha_inscripcion_inicial',
        'tutor_nombre', 'tutor_telefono', 'observaciones', 'id_carrera_actual',
    ];

    protected function casts(): array
    {
        return [
            'id_usuario'                => 'integer',
            'id_carrera_actual'         => 'integer',
            'fecha_inscripcion_inicial' => 'date',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
