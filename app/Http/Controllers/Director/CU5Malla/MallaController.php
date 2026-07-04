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

    public function storeNivel(Request $request, int $idCarrera)
    {
        return $this->malla->agregarNivel($request, $idCarrera);
    }

    public function destroyNivel(int $idNivel)
    {
        return $this->malla->eliminarNivel($idNivel);
    }

    public function storeMalla(Request $request)
    {
        return $this->malla->asignarMateriaAMalla($request);
    }

    public function destroyMalla(int $idMalla)
    {
        return $this->malla->quitarDeMalla($idMalla);
    }

    public function storeMateriaNueva(Request $request, int $idCarrera)
    {
        return $this->malla->crearYAsignarMateria($request, $idCarrera);
    }
}
