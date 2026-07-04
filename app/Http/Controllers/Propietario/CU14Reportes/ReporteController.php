<?php

namespace App\Http\Controllers\Propietario\CU14Reportes;

use App\Http\Controllers\Controller;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ReporteController extends Controller
{
    public function __construct(private ReporteService $reporte) {}

    public function index(Request $request)
    {
        $filtros = [
            'activo_usuarios' => $request->input('activo_usuarios', 'todos'),
            'activo_aulas'    => $request->input('activo_aulas',    'todos'),
            'activo_horarios' => $request->input('activo_horarios', 'todos'),
            'nombre_periodo'  => $request->input('nombre_periodo')  ?: null,
            'id_carrera'      => $request->input('id_carrera') ? (int) $request->input('id_carrera') : null,
            'fecha_desde'     => $request->input('fecha_desde') ?: null,
            'fecha_hasta'     => $request->input('fecha_hasta') ?: null,
        ];

        return Inertia::render('Propietario/CU14Reportes/Index', [
            'esPropietario' => Auth::user()?->id_rol === 1,
            'filtros'       => $filtros,
            ...$this->reporte->getDatos($filtros),
        ]);
    }
}
