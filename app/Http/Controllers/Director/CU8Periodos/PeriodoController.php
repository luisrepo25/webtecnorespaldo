<?php

namespace App\Http\Controllers\Director\CU8Periodos;

use App\Http\Controllers\Controller;
use App\Services\PeriodoService;
use Illuminate\Http\Request;

class PeriodoController extends Controller
{
    public function __construct(private PeriodoService $periodos)
    {
    }

    public function listar()
    {
        return $this->periodos->listar();
    }

    public function registrarPeriodo(Request $request)
    {
        return $this->periodos->registrarPeriodo($request);
    }

    public function actualizarPeriodo(Request $request, int $id)
    {
        return $this->periodos->actualizarPeriodo($request, $id);
    }

    public function cambiarEstado(int $id)
    {
        return $this->periodos->cambiarEstado($id);
    }

    public function registrarLote(Request $request)
    {
        return $this->periodos->registrarLote($request);
    }

    public function clonarSiguienteAnio()
    {
        return $this->periodos->clonarSiguienteAnio();
    }

    public function eliminarPeriodo(int $id)
    {
        return $this->periodos->eliminarPeriodo($id);
    }
}
