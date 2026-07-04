<?php

namespace App\Http\Controllers\Director\CU3Materias;

use App\Http\Controllers\Controller;
use App\Services\MateriaService;
use Illuminate\Http\Request;

class MateriaController extends Controller
{
    public function __construct(private MateriaService $materias)
    {
    }

    public function index(Request $request)
    {
        return $this->materias->listar($request);
    }

    public function store(Request $request)
    {
        return $this->materias->registrarMateria($request);
    }

    public function update(Request $request, int $id)
    {
        return $this->materias->actualizarMateria($request, $id);
    }

    public function toggleActivo(int $id)
    {
        return $this->materias->cambiarEstado($id);
    }

    public function porCarrera(int $id)
    {
        return $this->materias->porCarrera($id);
    }
}
