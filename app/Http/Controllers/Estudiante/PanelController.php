<?php

namespace App\Http\Controllers\Estudiante;

use App\Http\Controllers\Controller;
use App\Services\InscripcionService;
use App\Services\PagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PanelController extends Controller
{
    public function __construct(
        private InscripcionService $inscripciones,
        private PagoService $pagos,
    ) {
    }

    // ── Dashboard (resumen rápido) ───────────────────────────────────────────────
    public function index()
    {
        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();

        if (!$est) {
            return Inertia::render('Dashboard/Estudiante', ['estudiante' => null]);
        }

        $carrera  = $est->id_carrera_actual
            ? DB::table('carreras')->where('id_carrera', $est->id_carrera_actual)->first()
            : null;

        $matricula = DB::table('matricula_unica')->where('id_estudiante', $est->id_estudiante)->first();

        $afiliacion = DB::table('afiliaciones_estudiante')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('estado', 'activo')
            ->orderByDesc('fecha_inicio')
            ->first();

        $pagoCarrera = DB::table('pago_carrera_completa')
            ->where('id_estudiante', $est->id_estudiante)
            ->whereIn('estado', ['pagado', 'parcial'])
            ->orderByDesc('fecha_contrato')
            ->first();

        $resumen = $this->resumenAcademico($est, $carrera);

        $totalInscripciones = DB::table('inscripciones')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('estado', '!=', 'pendiente_matricula')
            ->count();

        return Inertia::render('Dashboard/Estudiante', [
            'estudiante' => [
                'id_estudiante'   => $est->id_estudiante,
                'legajo'          => $est->legajo,
                'tiene_matricula' => $matricula !== null,
                'matricula'       => $matricula ? [
                    'fecha_pago'   => $matricula->fecha_pago,
                    'monto_pagado' => (float) $matricula->monto_pagado,
                ] : null,
                'carrera' => $carrera ? [
                    'id_carrera' => $carrera->id_carrera,
                    'nombre'     => $carrera->nombre,
                    'tipo'       => $carrera->tipo,
                    'codigo'     => $carrera->codigo,
                ] : null,
            ],
            'afiliacion'  => $afiliacion ? [
                'tipo_programa' => $afiliacion->tipo_programa,
                'fecha_inicio'  => $afiliacion->fecha_inicio,
                'estado'        => $afiliacion->estado,
            ] : null,
            'pagoCarrera' => $pagoCarrera ? [
                'forma_pago'   => $pagoCarrera->forma_pago,
                'monto_total'  => (float) $pagoCarrera->monto_total,
                'monto_pagado' => (float) ($pagoCarrera->monto_pagado ?? 0),
                'estado'       => $pagoCarrera->estado,
            ] : null,
            'materiaEnCurso'            => $resumen['materiaEnCursoInfo'],
            'materiaReprobadaEsperando' => $resumen['materiaReprobadaEsperandoInfo'],
            'proximaMateria'            => $resumen['proximaMateriaInfo'],
            'totalInscripciones'        => $totalInscripciones,
        ]);
    }

    // ── Mis Materias: Plan de Carrera / Oferta del Semestre / Mis Grupos / Inscripciones
    public function materias()
    {
        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();

        if (!$est) {
            return Inertia::render('Estudiante/MisMaterias', [
                'estudiante' => null, 'inscripciones' => [],
                'gruposDisponibles' => [], 'afiliacion' => null, 'pagoCarrera' => null, 'planOpciones' => [],
            ]);
        }

        $carrera  = $est->id_carrera_actual
            ? DB::table('carreras')->where('id_carrera', $est->id_carrera_actual)->first()
            : null;

        $matricula = DB::table('matricula_unica')->where('id_estudiante', $est->id_estudiante)->first();

        // Afiliación activa — necesaria para inscribirse
        $afiliacion = DB::table('afiliaciones_estudiante')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('estado', 'activo')
            ->orderByDesc('fecha_inicio')
            ->first();

        // Plan de pago de carrera (creado por trigger al pagar)
        $pagoCarrera = DB::table('pago_carrera_completa')
            ->where('id_estudiante', $est->id_estudiante)
            ->whereIn('estado', ['pagado', 'parcial'])
            ->orderByDesc('fecha_contrato')
            ->first();

        // Opciones de plan — valores válidos según constraints del trigger
        $planOpciones = [];
        if ($carrera) {
            $costoTotal    = (float) ($carrera->costo_carrera_completa ?? 0);
            $totalMaterias = max(DB::table('malla_curricular')->where('id_carrera', $est->id_carrera_actual)->count(), 1);

            $planOpciones = [
                'contado' => [
                    'tipo'           => 'contado',
                    'titulo'         => 'Pago Total',
                    'descripcion'    => '20% de descuento. Cubre todas las materias.',
                    'monto_inicial'  => round($costoTotal * 0.80, 2),
                    'monto_original' => $costoTotal,
                    'ahorro'         => round($costoTotal * 0.20, 2),
                    'por_materia'    => 0,
                ],
                'credito' => [
                    'tipo'           => 'credito',
                    'titulo'         => 'Enganche + Materias',
                    'descripcion'    => 'Paga 30% ahora y el resto al inscribirte en cada materia.',
                    'monto_inicial'  => round($costoTotal * 0.30, 2),
                    'monto_original' => $costoTotal,
                    'ahorro'         => 0,
                    'por_materia'    => round($costoTotal * 0.70 / $totalMaterias, 2),
                ],
                'materia' => [
                    'tipo'           => 'materia',
                    'titulo'         => 'Pago por Materia',
                    'descripcion'    => 'Sin enganche. Pagas solo cuando te inscribes en cada materia.',
                    'monto_inicial'  => 0,
                    'monto_original' => $costoTotal,
                    'ahorro'         => 0,
                    'por_materia'    => round($costoTotal / $totalMaterias, 2),
                ],
            ];
        }

        // Mis inscripciones (excluir las que están en pendiente de pago)
        $inscripciones = DB::table('inscripciones as i')
            ->join('grupos as g',           'i.id_oferta',   '=', 'g.id_oferta')
            ->join('materias as m',          'g.id_materia',  '=', 'm.id_materia')
            ->join('periodos_dictado as pd', 'g.id_periodo',  '=', 'pd.id_periodo')
            ->join('horarios as h',          'g.id_horario',  '=', 'h.id_horario')
            ->join('aulas as a',             'g.id_aula',     '=', 'a.id_aula')
            ->join('profesores as p',        'g.id_profesor', '=', 'p.id_profesor')
            ->join('usuarios as u',          'p.id_usuario',  '=', 'u.id_usuario')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->where('i.estado', '!=', 'pendiente_matricula')
            ->select(
                'i.id_inscripcion', 'i.estado', 'i.calificacion_final', 'i.aprobado', 'i.fecha_inscripcion',
                'g.id_oferta', 'g.codigo_grupo',
                'm.nombre as materia_nombre', 'm.codigo as materia_codigo',
                'pd.nombre as periodo_nombre', 'pd.fecha_inicio as periodo_inicio', 'pd.fecha_fin as periodo_fin',
                'h.dia_semana', 'h.hora_inicio', 'h.hora_fin',
                'a.nombre as aula_nombre',
                DB::raw("u.nombre || ' ' || u.apellido as profesor_nombre"),
                DB::raw("p.archivo_cv /* v2 */ as profesor_cv")
            )
            ->orderBy('pd.fecha_inicio', 'desc')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        $gruposInscritos = array_column($inscripciones, 'id_oferta');

        $resumen = $this->resumenAcademico($est, $carrera);
        $cronogramaInscripcionGlobal     = $resumen['cronogramaInscripcionGlobal'];
        $sqlOcupadasGrupo                = $resumen['sqlOcupadasGrupo'];
        $idsPeriodoConInscripcionAbierta = $resumen['idsPeriodoConInscripcionAbierta'];
        $inscripcionesAbiertas           = $resumen['inscripcionesAbiertas'];
        $materiaEnCursoInfo              = $resumen['materiaEnCursoInfo'];
        $materiaReprobadaEsperandoInfo   = $resumen['materiaReprobadaEsperandoInfo'];
        $proximaMateriaInfo              = $resumen['proximaMateriaInfo'];
        $cronogramaInscripcion           = $cronogramaInscripcionGlobal; // para vista (compat)

        // Grupos disponibles — solo si hay ventana de inscripción abierta y una próxima materia
        $gruposDisponibles = [];
        if ($idsPeriodoConInscripcionAbierta->isNotEmpty() && $proximaMateriaInfo) {
            $proximaMateria = $proximaMateriaInfo['id_materia'];
            $query = DB::table('grupos as g')
                ->join('materias as m',          'g.id_materia',  '=', 'm.id_materia')
                ->join('periodos_dictado as pd', 'g.id_periodo',  '=', 'pd.id_periodo')
                ->join('horarios as h',          'g.id_horario',  '=', 'h.id_horario')
                ->join('aulas as a',             'g.id_aula',     '=', 'a.id_aula')
                ->join('profesores as p',        'g.id_profesor', '=', 'p.id_profesor')
                ->join('usuarios as u',          'p.id_usuario',  '=', 'u.id_usuario')
                ->whereIn('g.id_periodo', $idsPeriodoConInscripcionAbierta)
                ->where('g.id_materia', $proximaMateria)
                ->whereRaw('g.activo IS TRUE')
                ->whereRaw("{$sqlOcupadasGrupo} < g.vacantes_max");

            if (!empty($gruposInscritos)) {
                $query->whereNotIn('g.id_oferta', $gruposInscritos);
            }

            $gruposDisponibles = $query
                ->select(
                    'g.id_oferta', 'g.codigo_grupo', 'g.vacantes_max',
                    DB::raw("{$sqlOcupadasGrupo} as vacantes_ocupadas"),
                    'm.nombre as materia_nombre', 'm.codigo as materia_codigo',
                    'pd.id_periodo', 'pd.nombre as periodo_nombre',
                    'pd.fecha_inicio as periodo_inicio', 'pd.fecha_fin as periodo_fin',
                    'h.dia_semana', 'h.hora_inicio', 'h.hora_fin',
                    'a.nombre as aula_nombre',
                    DB::raw("u.nombre || ' ' || u.apellido as profesor_nombre"),
                    DB::raw("p.archivo_cv /* v2 */ as profesor_cv")
                )
                ->orderByRaw("CASE h.dia_semana WHEN 'lunes' THEN 1 WHEN 'martes' THEN 2 WHEN 'miercoles' THEN 3 WHEN 'jueves' THEN 4 WHEN 'viernes' THEN 5 WHEN 'sabado' THEN 6 WHEN 'domingo' THEN 7 ELSE 8 END")
                ->orderBy('h.hora_inicio')
                ->get()
                ->map(fn($r) => (array) $r)
                ->toArray();
        }

        // Maestro de oferta general — todos los grupos de la carrera en el período con inscripción abierta
        // Solo visible cuando las inscripciones están abiertas
        $ofertaGeneral = [];
        if ($carrera && $inscripcionesAbiertas && $idsPeriodoConInscripcionAbierta->isNotEmpty()) {
            $idsPeriodo = $idsPeriodoConInscripcionAbierta;

            $ofertaGeneral = DB::table('grupos as g')
                ->join('materias as m',          'g.id_materia',  '=', 'm.id_materia')
                ->join('periodos_dictado as pd', 'g.id_periodo',  '=', 'pd.id_periodo')
                ->join('horarios as h',          'g.id_horario',  '=', 'h.id_horario')
                ->join('aulas as a',             'g.id_aula',     '=', 'a.id_aula')
                ->join('profesores as p',        'g.id_profesor', '=', 'p.id_profesor')
                ->join('usuarios as u',          'p.id_usuario',  '=', 'u.id_usuario')
                ->leftJoin('malla_curricular as mc', function ($join) use ($est) {
                    $join->on('g.id_materia', '=', 'mc.id_materia')
                         ->where('mc.id_carrera', $est->id_carrera_actual);
                })
                ->leftJoin('niveles_carrera as n', 'mc.id_nivel', '=', 'n.id_nivel')
                ->whereIn('g.id_periodo', $idsPeriodo)
                ->whereRaw('g.activo IS TRUE')
                ->select(
                    'g.id_oferta', 'g.codigo_grupo', 'g.vacantes_max',
                    DB::raw("{$sqlOcupadasGrupo} as vacantes_ocupadas"),
                    'm.id_materia', 'm.nombre as materia_nombre', 'm.codigo as materia_codigo',
                    'pd.nombre as periodo_nombre',
                    'h.dia_semana', 'h.hora_inicio', 'h.hora_fin',
                    'a.nombre as aula_nombre',
                    DB::raw("u.nombre || ' ' || u.apellido as profesor_nombre"),
                    DB::raw('p.archivo_cv as profesor_cv'),
                    'n.numero_nivel', 'n.nombre as nivel_nombre',
                    'mc.orden_en_nivel'
                )
                ->orderByRaw('COALESCE(n.numero_nivel, 0)')
                ->orderByRaw('COALESCE(mc.orden_en_nivel, 0)')
                ->orderBy('m.nombre')
                ->orderBy('g.codigo_grupo')
                ->orderByRaw("CASE h.dia_semana WHEN 'lunes' THEN 1 WHEN 'martes' THEN 2 WHEN 'miercoles' THEN 3 WHEN 'jueves' THEN 4 WHEN 'viernes' THEN 5 WHEN 'sabado' THEN 6 WHEN 'domingo' THEN 7 ELSE 8 END")
                ->get()
                ->map(fn($r) => (array) $r)
                ->toArray();
        }

        return Inertia::render('Estudiante/MisMaterias', [
            'estudiante' => [
                'id_estudiante'   => $est->id_estudiante,
                'legajo'          => $est->legajo,
                'tiene_matricula' => $matricula !== null,
                'matricula'       => $matricula ? [
                    'fecha_pago'   => $matricula->fecha_pago,
                    'monto_pagado' => (float) $matricula->monto_pagado,
                ] : null,
                'carrera' => $carrera ? [
                    'id_carrera'             => $carrera->id_carrera,
                    'nombre'                 => $carrera->nombre,
                    'tipo'                   => $carrera->tipo,
                    'codigo'                 => $carrera->codigo,
                    'costo_carrera_completa' => (float) $carrera->costo_carrera_completa,
                ] : null,
            ],
            'afiliacion'  => $afiliacion ? [
                'tipo_programa' => $afiliacion->tipo_programa,
                'fecha_inicio'  => $afiliacion->fecha_inicio,
                'estado'        => $afiliacion->estado,
            ] : null,
            'pagoCarrera' => $pagoCarrera ? [
                'forma_pago'   => $pagoCarrera->forma_pago,
                'monto_total'  => (float) $pagoCarrera->monto_total,
                'monto_pagado' => (float) ($pagoCarrera->monto_pagado ?? 0),
                'estado'       => $pagoCarrera->estado,
                'fecha_contrato' => $pagoCarrera->fecha_contrato,
            ] : null,
            'planOpciones'           => $planOpciones,
            'inscripciones'          => $inscripciones,
            'gruposDisponibles'      => $gruposDisponibles,
            'ofertaGeneral'          => $ofertaGeneral,
            'proximaMateria'         => $proximaMateriaInfo,
            'materiaEnCurso'         => $materiaEnCursoInfo,
            'materiaReprobadaEsperando' => $materiaReprobadaEsperandoInfo,
            'cronogramaInscripcion'  => $cronogramaInscripcion ? [
                'nombre'      => $cronogramaInscripcion->nombre,
                'fecha_inicio' => $cronogramaInscripcion->fecha_inicio,
                'fecha_fin'    => $cronogramaInscripcion->fecha_fin,
            ] : null,
        ]);
    }

    // ── Resumen académico compartido: materia en curso/reprobada/próxima ────────
    // Usado tanto por el Dashboard (resumen rápido) como por Mis Materias (oferta completa).
    private function resumenAcademico($est, $carrera): array
    {
        // Cronograma de inscripción global (fallback cuando el período no tiene fechas propias)
        $cronogramaInscripcionGlobal = DB::table('cronogramas')
            ->where('tipo_periodo', 'inscripcion')
            ->where('activo', true)
            ->whereRaw('CURRENT_DATE BETWEEN fecha_inicio AND fecha_fin')
            ->orderBy('fecha_inicio', 'desc')
            ->first();

        // Inicio de la convocatoria de inscripción vigente: las vacantes ocupadas de
        // un grupo se cuentan desde esta fecha (no por estado='activo'), porque el
        // cupo de este mes NO debe liberarse solo porque un alumno ya fue calificado
        // (reprobado/aprobado) mientras la inscripción de este mismo mes sigue abierta
        // — otros estudiantes todavía pueden inscribirse en ese cupo. El contador
        // recién vuelve a cero cuando abre la convocatoria del siguiente mes (otra
        // fecha de inicio), no antes.
        $ventanaInscripcionDesde = $cronogramaInscripcionGlobal
            ? now()->parse($cronogramaInscripcionGlobal->fecha_inicio)->toDateString()
            : '1900-01-01';
        // Cupos ocupados = estudiantes activos/pendientes (sin importar cuándo inscribieron,
        // siguen ocupando el cupo) MÁS quienes se inscribieron en esta ventana aunque ya
        // estén calificados (el cupo no se libera hasta que cierre la convocatoria).
        $sqlOcupadasGrupo = "(SELECT COUNT(*) FROM inscripciones ii WHERE ii.id_oferta = g.id_oferta AND ii.estado != 'retirado' AND (ii.estado IN ('activo','pendiente_matricula') OR ii.fecha_inscripcion::date >= COALESCE(pd.fecha_inicio_inscripcion, '{$ventanaInscripcionDesde}'::date)))";

        // Períodos de la carrera del estudiante con ventana de inscripción abierta.
        // Lógica: si el período tiene fecha_inicio_inscripcion → usa esas fechas propias
        // (la inscripción suele abrir ANTES de que el período de clases empiece, por eso
        // no se filtra aquí por fecha_inicio/fecha_fin del dictado).
        // Si no tiene fechas propias → acepta si el cronograma global de inscripción está
        // activo Y el período de clases está vigente.
        $periodosCarrera = [];
        if ($carrera) {
            $periodosCarrera = DB::table('periodos_dictado as pd')
                ->where('pd.id_carrera', $est->id_carrera_actual)
                ->whereNull('pd.id_nivel')
                ->whereRaw('pd.activo IS TRUE')
                ->select('pd.id_periodo', 'pd.fecha_inicio', 'pd.fecha_fin', 'pd.fecha_inicio_inscripcion', 'pd.fecha_fin_inscripcion')
                ->get();
        }

        // Filtrar períodos con inscripción abierta
        $idsPeriodoConInscripcionAbierta = collect($periodosCarrera)->filter(function ($p) use ($cronogramaInscripcionGlobal) {
            if ($p->fecha_inicio_inscripcion && $p->fecha_fin_inscripcion) {
                // Período tiene fechas propias → usar esas
                return now()->toDateString() >= $p->fecha_inicio_inscripcion
                    && now()->toDateString() <= $p->fecha_fin_inscripcion;
            }
            // Sin fechas propias → depende del cronograma global y de que el período esté vigente
            return $cronogramaInscripcionGlobal !== null
                && now()->toDateString() >= $p->fecha_inicio
                && now()->toDateString() <= $p->fecha_fin;
        })->pluck('id_periodo');

        $inscripcionesAbiertas = $idsPeriodoConInscripcionAbierta->isNotEmpty();

        // Las materias son mensuales y secuenciales: si el estudiante ya tiene una
        // inscripción 'activo' (materia en curso, aún no aprobada), no se le debe
        // ofrecer ningún grupo nuevo hasta que la complete — coincide con el guard
        // de inscribir().
        $materiaEnCurso = DB::table('inscripciones as i')
            ->join('grupos as g2',    'i.id_oferta',  '=', 'g2.id_oferta')
            ->join('materias as m2',  'g2.id_materia', '=', 'm2.id_materia')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->where('i.estado', 'activo')
            ->select('m2.id_materia', 'm2.nombre', 'm2.codigo')
            ->first();
        $materiaEnCursoInfo = $materiaEnCurso ? (array) $materiaEnCurso : null;

        // Las materias son modulares (1 mes = 1 materia). El "mes" de una materia
        // NO está delimitado por periodos_dictado.fecha_fin (eso es la ventana larga
        // de dictado de la carrera, puede durar varios meses) sino por el ciclo de
        // inscripción mensual: si el grupo donde reprobó pertenece a un período que
        // SIGUE siendo el que tiene inscripción abierta ahora mismo, sigue dentro de
        // ese mismo mes y debe esperar a que se lance la convocatoria del siguiente
        // mes (nuevo período con inscripción abierta) para reintentarla.
        $materiaReprobadaEsperando = DB::table('inscripciones as i')
            ->join('grupos as g2',   'i.id_oferta',   '=', 'g2.id_oferta')
            ->join('materias as m2', 'g2.id_materia', '=', 'm2.id_materia')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->where('i.estado', 'reprobado')
            ->whereIn('g2.id_periodo', $idsPeriodoConInscripcionAbierta)
            ->select('m2.id_materia', 'm2.nombre', 'm2.codigo')
            ->first();

        $materiaReprobadaEsperandoInfo = null;
        if ($materiaReprobadaEsperando) {
            $materiaReprobadaEsperandoInfo = (array) $materiaReprobadaEsperando;

            // Hint informativo: próxima convocatoria de inscripción ya programada (si existe).
            // Solo cronogramas 'mensual' — uno 'semestral'/'anual' no es la convocatoria
            // del siguiente mes, es la ventana larga de otro ciclo y daría una fecha falsa.
            $proximaConvocatoria = DB::table('cronogramas')
                ->where('tipo_periodo', 'inscripcion')
                ->where('modalidad', 'mensual')
                ->where('activo', true)
                ->where('fecha_inicio', '>', now()->toDateString())
                ->orderBy('fecha_inicio')
                ->first();

            if ($proximaConvocatoria) {
                $materiaReprobadaEsperandoInfo['proxima_convocatoria'] = $proximaConvocatoria->fecha_inicio;
            }
        }

        $proximaMateriaInfo = null;
        if ($carrera && $inscripcionesAbiertas && !$materiaEnCurso && !$materiaReprobadaEsperando) {
            // ── Calcular la próxima materia del estudiante en su malla ────────
            $mallaOrdenada = DB::table('malla_curricular as mc')
                ->leftJoin('niveles_carrera as n', 'mc.id_nivel', '=', 'n.id_nivel')
                ->where('mc.id_carrera', $est->id_carrera_actual)
                ->where('mc.obligatoria', true)
                ->orderByRaw('COALESCE(n.numero_nivel, 0)')
                ->orderByRaw('COALESCE(mc.orden_en_nivel, 0)')
                ->pluck('mc.id_materia')
                ->toArray();

            $materiasYaHechas = DB::table('inscripciones as i')
                ->join('grupos as g2', 'i.id_oferta', '=', 'g2.id_oferta')
                ->where('i.id_estudiante', $est->id_estudiante)
                ->where('i.estado', 'aprobado')
                ->pluck('g2.id_materia')
                ->toArray();

            $proximaMateria = null;
            foreach ($mallaOrdenada as $mId) {
                if (!in_array($mId, $materiasYaHechas)) {
                    $proximaMateria = $mId;
                    break;
                }
            }

            if ($proximaMateria) {
                $mat = DB::table('materias')->where('id_materia', $proximaMateria)
                    ->select('id_materia', 'nombre', 'codigo')->first();
                $proximaMateriaInfo = $mat ? (array) $mat : null;
            }
        }

        return [
            'cronogramaInscripcionGlobal'      => $cronogramaInscripcionGlobal,
            'ventanaInscripcionDesde'          => $ventanaInscripcionDesde,
            'sqlOcupadasGrupo'                 => $sqlOcupadasGrupo,
            'idsPeriodoConInscripcionAbierta'  => $idsPeriodoConInscripcionAbierta,
            'inscripcionesAbiertas'            => $inscripcionesAbiertas,
            'materiaEnCursoInfo'               => $materiaEnCursoInfo,
            'materiaReprobadaEsperandoInfo'    => $materiaReprobadaEsperandoInfo,
            'proximaMateriaInfo'               => $proximaMateriaInfo,
        ];
    }

    // ── Mi Malla Académica ──────────────────────────────────────────────────────
    public function malla()
    {
        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();

        if (!$est || !$est->id_carrera_actual) {
            return Inertia::render('Estudiante/Malla', ['carrera' => null, 'niveles' => []]);
        }

        $carrera = DB::table('carreras')->where('id_carrera', $est->id_carrera_actual)->first();

        $mallaRows = DB::table('malla_curricular as mc')
            ->leftJoin('niveles_carrera as n', 'mc.id_nivel', '=', 'n.id_nivel')
            ->join('materias as m', 'mc.id_materia', '=', 'm.id_materia')
            ->where('mc.id_carrera', $est->id_carrera_actual)
            ->where('mc.obligatoria', true)
            ->select(
                'm.id_materia', 'm.nombre as materia_nombre', 'm.codigo as materia_codigo',
                'n.numero_nivel', 'n.nombre as nivel_nombre'
            )
            ->orderByRaw('COALESCE(n.numero_nivel, 0)')
            ->orderByRaw('COALESCE(mc.orden_en_nivel, 0)')
            ->get();

        // Último intento por materia (más reciente primero → unique() se queda con ese)
        $ultimoIntentoPorMateria = DB::table('inscripciones as i')
            ->join('grupos as g', 'i.id_oferta', '=', 'g.id_oferta')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->whereIn('i.estado', ['activo', 'aprobado', 'reprobado'])
            ->select('g.id_materia', 'i.estado', 'i.calificacion_final', 'i.fecha_inscripcion', 'i.fecha_aprobacion')
            ->orderByDesc('i.fecha_inscripcion')
            ->get()
            ->unique('id_materia')
            ->keyBy('id_materia');

        $niveles = [];
        foreach ($mallaRows as $row) {
            $key = $row->numero_nivel ?? 0;
            if (!isset($niveles[$key])) {
                $niveles[$key] = [
                    'numero_nivel' => $row->numero_nivel,
                    'nivel_nombre' => $row->nivel_nombre ?? ($row->numero_nivel ? "Nivel {$row->numero_nivel}" : 'Módulos'),
                    'materias'     => [],
                ];
            }
            $h = $ultimoIntentoPorMateria->get($row->id_materia);
            $niveles[$key]['materias'][] = [
                'id_materia'         => $row->id_materia,
                'nombre'             => $row->materia_nombre,
                'codigo'             => $row->materia_codigo,
                'estado'             => $h ? $h->estado : 'pendiente', // activo | aprobado | reprobado | pendiente
                'calificacion_final' => $h && $h->calificacion_final !== null ? (float) $h->calificacion_final : null,
                'fecha'              => $h ? ($h->fecha_aprobacion ?? $h->fecha_inscripcion) : null,
            ];
        }

        return Inertia::render('Estudiante/Malla', [
            'carrera' => $carrera ? ['nombre' => $carrera->nombre, 'codigo' => $carrera->codigo] : null,
            'niveles' => array_values($niveles),
        ]);
    }

    // ── Historial de Notas ──────────────────────────────────────────────────────
    public function notas()
    {
        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();

        if (!$est) {
            return Inertia::render('Estudiante/Notas', ['notas' => []]);
        }

        $notas = DB::table('inscripciones as i')
            ->join('grupos as g',           'i.id_oferta',  '=', 'g.id_oferta')
            ->join('materias as m',         'g.id_materia', '=', 'm.id_materia')
            ->join('periodos_dictado as pd','g.id_periodo', '=', 'pd.id_periodo')
            ->leftJoin('malla_curricular as mc', function ($join) use ($est) {
                $join->on('m.id_materia', '=', 'mc.id_materia')
                     ->where('mc.id_carrera', $est->id_carrera_actual);
            })
            ->leftJoin('niveles_carrera as n', 'mc.id_nivel', '=', 'n.id_nivel')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->whereIn('i.estado', ['aprobado', 'reprobado'])
            ->select(
                'i.id_inscripcion', 'i.estado', 'i.calificacion_final', 'i.fecha_aprobacion', 'i.fecha_inscripcion',
                'm.id_materia', 'm.nombre as materia_nombre', 'm.codigo as materia_codigo',
                'pd.nombre as periodo_nombre',
                'n.numero_nivel', 'n.nombre as nivel_nombre'
            )
            ->orderByDesc('i.fecha_aprobacion')
            ->orderByDesc('i.fecha_inscripcion')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        return Inertia::render('Estudiante/Notas', ['notas' => $notas]);
    }

    // ── Historial de Pagos ──────────────────────────────────────────────────────
    public function pagos()
    {
        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();

        if (!$est) {
            return Inertia::render('Estudiante/Pagos', ['matricula' => null, 'planCarrera' => null, 'pagosMateria' => []]);
        }

        $matricula = DB::table('matricula_unica')->where('id_estudiante', $est->id_estudiante)->first();

        $planCarrera = DB::table('pago_carrera_completa')
            ->where('id_estudiante', $est->id_estudiante)
            ->orderByDesc('fecha_contrato')
            ->first();

        $pagosMateria = DB::table('pago_materia_suelta as pms')
            ->join('inscripciones as i', 'pms.id_inscripcion', '=', 'i.id_inscripcion')
            ->join('grupos as g',        'i.id_oferta',        '=', 'g.id_oferta')
            ->join('materias as m',      'g.id_materia',       '=', 'm.id_materia')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->select(
                'pms.id_pago_materia', 'pms.monto_pagado', 'pms.fecha_pago', 'pms.estado',
                'm.nombre as materia_nombre', 'm.codigo as materia_codigo'
            )
            ->orderByDesc('pms.fecha_pago')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        return Inertia::render('Estudiante/Pagos', [
            'matricula' => $matricula ? [
                'fecha_pago'   => $matricula->fecha_pago,
                'monto_pagado' => (float) $matricula->monto_pagado,
                'estado'       => $matricula->estado,
                'comprobante'  => $matricula->comprobante,
            ] : null,
            'planCarrera' => $planCarrera ? [
                'forma_pago'         => $planCarrera->forma_pago,
                'monto_total'        => (float) $planCarrera->monto_total,
                'monto_pagado'       => (float) ($planCarrera->monto_pagado ?? 0),
                'estado'             => $planCarrera->estado,
                'fecha_contrato'     => $planCarrera->fecha_contrato,
                'materias_cubiertas' => $planCarrera->materias_cubiertas,
            ] : null,
            'pagosMateria' => $pagosMateria,
        ]);
    }

    // ── Elegir plan de pago de carrera ────────────────────────────────────────
    public function elegirPlan(Request $request, string $tipo)
    {
        return $this->pagos->elegirPlan($request, $tipo);
    }

    // ── Página de pago del plan de carrera ────────────────────────────────────
    public function pagoCarrera(int $transId)
    {
        return $this->pagos->pagoCarrera($transId);
    }

    // ── Polling estado del plan de carrera ────────────────────────────────────
    public function estadoPlan(int $transId)
    {
        return $this->pagos->estadoPlan($transId);
    }

    // ── Inscribirse en un grupo ────────────────────────────────────────────────
    public function inscribir(Request $request, int $idOferta)
    {
        return $this->inscripciones->inscribir($request, $idOferta);
    }

    // ── Página de pago de materia ─────────────────────────────────────────────
    public function pagoInscripcion(int $transId)
    {
        return $this->pagos->pagoInscripcion($transId);
    }

    // ── Polling estado de pago de materia ─────────────────────────────────────
    public function estadoInscripcion(int $transId)
    {
        return $this->pagos->estadoInscripcion($transId);
    }
}
