<?php

namespace App\Http\Controllers\Director\CU4Carreras;

use App\Http\Controllers\Controller;
use App\Services\CarreraService;
use Illuminate\Http\Request;

class CarreraController extends Controller
{
    public function __construct(private CarreraService $carreras)
    {
    }

    public function listar(Request $request)
    {
        return $this->carreras->listar($request);
    }

    public function registrarCarrera(Request $request)
    {
        return $this->carreras->registrarCarrera($request);
    }

    public function actualizarCarrera(Request $request, int $id)
    {
        return $this->carreras->actualizarCarrera($request, $id);
    }

    public function cambiarEstado(int $id)
    {
        return $this->carreras->cambiarEstado($id);
    }
}
