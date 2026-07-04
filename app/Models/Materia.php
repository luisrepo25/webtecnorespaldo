<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Materia extends Model
{
    protected $table = 'materias';
    protected $primaryKey = 'id_materia';
    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'duracion_meses',
        'costo_mensual', 'creditos', 'activo', 'id_materia_requisito',
    ];

    protected function casts(): array
    {
        return [
            'activo'               => 'boolean',
            'costo_mensual'        => 'decimal:2',
            'duracion_meses'       => 'integer',
            'creditos'             => 'integer',
            'id_materia_requisito' => 'integer',
        ];
    }

    public function requisito()
    {
        return $this->belongsTo(Materia::class, 'id_materia_requisito', 'id_materia');
    }
}
