<?php

namespace App\Http\Controllers\Profesor\CU12Evaluaciones;

use App\Http\Controllers\Controller;
use App\Services\EvaluacionService;
use Illuminate\Http\Request;

class EvaluacionController extends Controller
{
    public function __construct(private EvaluacionService $evaluaciones)
    {
    }

    public function guardarNotas(Request $request)
    {
        return $this->evaluaciones->guardarNotas($request);
    }

    public function guardarNotasGrupo(Request $request)
    {
        return $this->evaluaciones->guardarNotasGrupo($request);
    }
}
