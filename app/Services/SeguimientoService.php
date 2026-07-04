<?php

namespace App\Services;

use App\Models\Carrera;
use App\Models\Estudiante;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeguimientoService
{
    // ── CU13.1 — Listado ─────────────────────────────────────────────────────

    public function listarEstudiantes(string $buscar): Collection
    {
        $query = Usuario::where('id_rol', 5)
            ->with('estudiante')
            ->orderBy('apellido')
            ->orderBy('nombre');

        if ($buscar) {
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre',    'ilike', "%{$buscar}%")
                  ->orWhere('apellido', 'ilike', "%{$buscar}%")
                  ->orWhere('dni',      'ilike', "%{$buscar}%")
                  ->orWhere('email',    'ilike', "%{$buscar}%");
            });
        }

        $usuarios   = $query->get();
        $idCarreras = $usuarios->map(fn($u) => $u->estudiante?->id_carrera_actual)->filter()->unique();
        $carreras   = Carrera::whereIn('id_carrera', $idCarreras)->get()->keyBy('id_carrera');

        return $usuarios->map(function ($u) use ($carreras) {
            $c = $carreras->get($u->estudiante?->id_carrera_actual);
            return [
                'id_usuario' => $u->id_usuario,
                'nombre'     => $u->nombre,
                'apellido'   => $u->apellido,
                'email'      => $u->email,
                'dni'        => $u->dni,
                'activo'     => $u->activo,
                'legajo'     => $u->estudiante?->legajo,
                'carrera'    => $c ? ['id' => $c->id_carrera, 'nombre' => $c->nombre] : null,
            ];
        });
    }

    public function resolverCarrera(?int $idCarrera, bool $withDuracion = false): ?array
    {
        if (!$idCarrera) return null;
        $c = Carrera::find($idCarrera);
        if (!$c) return null;

        $data = ['id' => $c->id_carrera, 'nombre' => $c->nombre];
        if ($withDuracion) $data['duracion_niveles'] = $c->duracion_niveles;
        return $data;
    }

    // ── CU13.2 — Historial ───────────────────────────────────────────────────

    public function getHistorialCompleto(Estudiante $estudiante): Collection
    {
        $historial = DB::table('inscripciones as i')
            ->join('grupos as g',          'i.id_oferta',  '=', 'g.id_oferta')
            ->join('materias as m',         'g.id_materia', '=', 'm.id_materia')
            ->join('periodos_dictado as p', 'g.id_periodo', '=', 'p.id_periodo')
            ->where('i.id_estudiante', $estudiante->id_estudiante)
            ->select(
                'i.id_inscripcion', 'i.estado', 'i.fecha_inscripcion',
                'i.calificacion_final', 'i.aprobado', 'i.observaciones',
                'm.nombre as materia', 'm.codigo as materia_codigo', 'm.id_materia',
                'p.nombre as periodo', 'p.tipo_periodo', 'p.fecha_inicio', 'p.fecha_fin',
                'g.id_oferta', 'g.codigo_grupo'
            )
            ->orderByDesc('p.fecha_inicio')
            ->get();

        $evalsPorInscripcion = DB::table('evaluaciones')
            ->whereIn('id_inscripcion', $historial->pluck('id_inscripcion'))
            ->orderByRaw("CASE tipo WHEN 'parcial1' THEN 1 WHEN 'parcial2' THEN 2 WHEN 'final' THEN 3 ELSE 4 END")
            ->get()
            ->groupBy('id_inscripcion');

        return $historial->map(function ($ins) use ($evalsPorInscripcion) {
            $arr = (array) $ins;
            $arr['aprobado']     = (bool) ($ins->aprobado ?? false);
            $arr['evaluaciones'] = array_values(
                $evalsPorInscripcion->get($ins->id_inscripcion)?->toArray() ?? []
            );
            return $arr;
        });
    }

    public function calcularResumen(Collection $historial, ?array $carrera): array
    {
        $finalizadas = $historial->filter(fn($i) => $i['calificacion_final'] !== null);
        $aprobadas   = $historial->filter(fn($i) => $i['aprobado'] === true)->count();
        $reprobadas  = $finalizadas->filter(fn($i) => !$i['aprobado'])->count();
        $notas       = $finalizadas->pluck('calificacion_final')->filter();

        $totalMalla = $carrera
            ? DB::table('malla_curricular')->where('id_carrera', $carrera['id'])->count()
            : 0;

        return [
            'total_materias_cursadas' => $historial->count(),
            'materias_aprobadas'      => $aprobadas,
            'materias_reprobadas'     => $reprobadas,
            'promedio_general'        => $notas->count() ? round($notas->avg(), 2) : null,
            'tasa_aprobacion'         => $finalizadas->count() > 0
                ? round($aprobadas / $finalizadas->count() * 100, 1)
                : null,
            'progreso_carrera'        => ($totalMalla > 0 && $aprobadas > 0)
                ? round($aprobadas / $totalMalla * 100, 1)
                : null,
        ];
    }

    public function validarRecurso(int $idUsuario, int $idMateria): array
    {
        $estudiante = Estudiante::where('id_usuario', $idUsuario)->first();

        if (!$estudiante) {
            return ['puede_recursar' => false, 'mensaje' => 'Estudiante no encontrado.'];
        }

        $intentos = DB::table('inscripciones as i')
            ->join('grupos as g', 'i.id_oferta', '=', 'g.id_oferta')
            ->where('i.id_estudiante', $estudiante->id_estudiante)
            ->where('g.id_materia', $idMateria)
            ->select('i.id_inscripcion', 'i.estado', 'i.aprobado', 'i.calificacion_final')
            ->get();

        if ($intentos->isEmpty()) {
            return ['puede_recursar' => false, 'mensaje' => 'No cursó esta materia anteriormente.'];
        }

        if ($intentos->contains(fn($i) => (bool) $i->aprobado === true)) {
            return ['puede_recursar' => false, 'mensaje' => 'La materia ya fue aprobada.'];
        }

        if ($intentos->contains(fn($i) => $i->estado === 'activo')) {
            return ['puede_recursar' => false, 'mensaje' => 'Actualmente está cursando esta materia.'];
        }

        return [
            'puede_recursar' => true,
            'intentos'       => $intentos->count(),
            'mensaje'        => "Puede recursar. Intentos previos: {$intentos->count()}.",
        ];
    }
}
