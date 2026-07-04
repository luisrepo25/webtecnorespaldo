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

    public function index()
    {
        return $this->grupos->index();
    }

    public function store(Request $request)
    {
        return $this->grupos->store($request);
    }

    public function update(Request $request, int $id)
    {
        return $this->grupos->update($request, $id);
    }

    public function toggleActivo(int $id)
    {
        return $this->grupos->toggleActivo($id);
    }

    public function destroy(int $id)
    {
        return $this->grupos->destroy($id);
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
