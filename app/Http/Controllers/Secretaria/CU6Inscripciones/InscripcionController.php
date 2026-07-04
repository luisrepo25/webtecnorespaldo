<?php

namespace App\Http\Controllers\Secretaria\CU6Inscripciones;

use App\Http\Controllers\Controller;
use App\Services\InscripcionService;
use Illuminate\Http\Request;

class InscripcionController extends Controller
{
    public function __construct(private InscripcionService $inscripciones)
    {
    }

    public function index()
    {
        return $this->inscripciones->index();
    }

    public function registrarInscripcionManual(Request $request)
    {
        return $this->inscripciones->registrarInscripcionManual($request);
    }
}
