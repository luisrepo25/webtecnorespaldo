<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Periodo extends Model
{
    protected $table      = 'periodos_dictado';
    protected $primaryKey = 'id_periodo';
    public    $timestamps = false;

    protected $fillable = [
        'nombre',
        'tipo_periodo',
        'fecha_inicio',
        'fecha_fin',
        'id_nivel',
        'activo',
        'max_materias',
        'id_carrera',
        'fecha_inicio_inscripcion',
        'fecha_fin_inscripcion',
    ];

    protected $casts = [
        'activo'                   => 'boolean',
        'id_nivel'                 => 'integer',
        'id_carrera'               => 'integer',
        'max_materias'             => 'integer',
        'fecha_inicio'             => 'date',
        'fecha_fin'                => 'date',
        'fecha_inicio_inscripcion' => 'date',
        'fecha_fin_inscripcion'    => 'date',
    ];

    public function carrera()
    {
        return $this->belongsTo(Carrera::class, 'id_carrera', 'id_carrera');
    }

    public function grupos()
    {
        return $this->hasMany(Grupo::class, 'id_periodo', 'id_periodo');
    }
}
