<?php

namespace App\Http\Controllers\Director\CU5Malla;

use App\Http\Controllers\Controller;
use App\Services\MallaService;
use Illuminate\Http\Request;

class MallaController extends Controller
{
    public function __construct(private MallaService $malla)
    {
    }

    public function agregarNivel(Request $request, int $idCarrera)
    {
        return $this->malla->agregarNivel($request, $idCarrera);
    }

    public function eliminarNivel(int $idNivel)
    {
        return $this->malla->eliminarNivel($idNivel);
    }

    public function asignarMateriaAMalla(Request $request)
    {
        return $this->malla->asignarMateriaAMalla($request);
    }

    public function quitarDeMalla(int $idMalla)
    {
        return $this->malla->quitarDeMalla($idMalla);
    }

    public function crearYAsignarMateria(Request $request, int $idCarrera)
    {
        return $this->malla->crearYAsignarMateria($request, $idCarrera);
    }
}
