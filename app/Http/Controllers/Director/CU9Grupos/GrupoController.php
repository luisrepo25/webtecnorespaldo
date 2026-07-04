<?php

namespace App\Http\Controllers\Director\CU9Grupos;

use App\Http\Controllers\Controller;
use App\Services\GrupoService;
use Illuminate\Http\Request;

class GrupoController extends Controller
{
    public function __construct(private GrupoService $grupos)
    {
    }

    public function listar()
    {
        return $this->grupos->listar();
    }

    public function registrarGrupo(Request $request)
    {
        return $this->grupos->registrarGrupo($request);
    }

    public function actualizarGrupo(Request $request, int $id)
    {
        return $this->grupos->actualizarGrupo($request, $id);
    }

    public function cambiarEstado(int $id)
    {
        return $this->grupos->cambiarEstado($id);
    }

    public function eliminarGrupo(int $id)
    {
        return $this->grupos->eliminarGrupo($id);
    }

    public function clonar(Request $request)
    {
        return $this->grupos->clonar($request);
    }

    public function destroyPeriodo(int $id)
    {
        return $this->grupos->destroyPeriodo($id);
    }

    public function inscritos(int $idOferta)
    {
        return $this->grupos->inscritos($idOferta);
    }
}
