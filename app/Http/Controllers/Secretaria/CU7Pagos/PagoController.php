<?php

namespace App\Http\Controllers\Secretaria\CU7Pagos;

use App\Http\Controllers\Controller;
use App\Services\PagoService;
use Illuminate\Http\Request;

class PagoController extends Controller
{
    public function __construct(private PagoService $pagos)
    {
    }

    public function index(Request $request)
    {
        return $this->pagos->index($request);
    }

    public function show(int $id)
    {
        return $this->pagos->show($id);
    }

    public function registrarMatricula(Request $request, int $id)
    {
        return $this->pagos->registrarMatricula($request, $id);
    }

    public function registrarCarrera(Request $request, int $id)
    {
        return $this->pagos->registrarCarrera($request, $id);
    }

    public function pagarCuota(int $idPago, int $numCuota)
    {
        return $this->pagos->pagarCuota($idPago, $numCuota);
    }
}
