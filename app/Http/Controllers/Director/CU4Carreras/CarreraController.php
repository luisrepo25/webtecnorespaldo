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

    public function index(Request $request)
    {
        return $this->carreras->listar($request);
    }

    public function store(Request $request)
    {
        return $this->carreras->registrarCarrera($request);
    }

    public function update(Request $request, int $id)
    {
        return $this->carreras->actualizarCarrera($request, $id);
    }

    public function toggleActivo(int $id)
    {
        return $this->carreras->cambiarEstado($id);
    }
}
