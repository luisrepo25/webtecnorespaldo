<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inscripcion extends Model
{
    protected $table      = 'inscripciones';
    protected $primaryKey = 'id_inscripcion';
    public    $timestamps = false;

    protected $fillable = [
        'id_estudiante',
        'id_oferta',
        'fecha_inscripcion',
        'estado',
        'calificacion_final',
        'aprobado',
        'observaciones',
        'fecha_aprobacion',
    ];

    protected $casts = [
        'aprobado'           => 'boolean',
        'calificacion_final' => 'decimal:2',
        'fecha_inscripcion'  => 'datetime',
        'fecha_aprobacion'   => 'date',
    ];

    public function estudiante()
    {
        return $this->belongsTo(Estudiante::class, 'id_estudiante', 'id_estudiante');
    }

    public function oferta()
    {
        return $this->belongsTo(Grupo::class, 'id_oferta', 'id_oferta');
    }
}
