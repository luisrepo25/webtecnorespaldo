<?php

namespace App\Http\Controllers\Propietario\CU13Seguimiento;

use App\Http\Controllers\Controller;
use App\Models\Estudiante;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Services\SeguimientoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SeguimientoController extends Controller
{
    public function __construct(private SeguimientoService $seguimiento) {}

    // ── CU13.1 — Listado de estudiantes con búsqueda ──────────────────────────
    public function index(Request $request)
    {
        $buscar = $request->input('buscar', '');
        return Inertia::render('Propietario/CU13Seguimiento/Index', [
            'estudiantes' => $this->seguimiento->listarEstudiantes($buscar),
            'filtros'     => ['buscar' => $buscar],
        ]);
    }

    // ── CU13.2 — Historial académico completo de un estudiante ────────────────
    public function show(int $id)
    {
        $usuario    = Usuario::where('id_usuario', $id)->where('id_rol', 5)->firstOrFail();
        $estudiante = Estudiante::where('id_usuario', $id)->first();
        $carrera    = $this->seguimiento->resolverCarrera($estudiante?->id_carrera_actual, withDuracion: true);

        $historial = $estudiante
            ? $this->seguimiento->getHistorialCompleto($estudiante)
            : collect();

        return Inertia::render('Propietario/CU13Seguimiento/Show', [
            'estudiante' => [
                'id_usuario'     => $usuario->id_usuario,
                'nombre'         => $usuario->nombre,
                'apellido'       => $usuario->apellido,
                'email'          => $usuario->email,
                'dni'            => $usuario->dni,
                'telefono'       => $usuario->telefono,
                'activo'         => $usuario->activo,
                'legajo'         => $estudiante?->legajo,
                'tutor_nombre'   => $estudiante?->tutor_nombre,
                'tutor_telefono' => $estudiante?->tutor_telefono,
                'observaciones'  => $estudiante?->observaciones,
                'fecha_inicio'   => $estudiante?->fecha_inscripcion_inicial,
            ],
            'carrera'  => $carrera,
            'historial' => $historial->values()->toArray(),
            'resumen'   => $this->seguimiento->calcularResumen($historial, $carrera),
        ]);
    }

    // ── CU13.3 — Registrar abandono de carrera ────────────────────────────────
    public function registrarAbandono(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:500']);

        $estudiante = Estudiante::where('id_usuario', $id)->firstOrFail();
        $nota = '[ABANDONO ' . now()->format('d/m/Y') . '] ' . $request->motivo;
        $prev = $estudiante->observaciones ? $estudiante->observaciones . "\n" : '';
        $estudiante->update(['observaciones' => $prev . $nota]);

        BitacoraService::registrar('abandono_carrera', "Abandono registrado para estudiante #{$estudiante->id_estudiante}. Motivo: {$request->motivo}");
        return back()->with('success', 'Abandono de carrera registrado.');
    }

    // ── CU13.4 — Validar si puede recursar una materia ────────────────────────
    public function validarRecurso(int $idUsuario, int $idMateria)
    {
        return response()->json(
            $this->seguimiento->validarRecurso($idUsuario, $idMateria)
        );
    }
}
