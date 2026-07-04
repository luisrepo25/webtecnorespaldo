<?php

namespace App\Services;

use App\Models\Carrera;
use App\Models\Estudiante;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class InscripcionService
{
    // ── CU6 (Secretaria): inscripción manual en caja ──────────────────────────
    public function index()
    {
        $carreras = Carrera::whereRaw('activo IS TRUE')->get();
        return Inertia::render('Secretaria/CU6Inscripciones/Index', [
            'carreras' => $carreras
        ]);
    }

    public function registrarInscripcionManual(Request $request)
    {
        $request->validate([
            'nombre'     => ['required','string','max:100','regex:/^[\pL\s\'\-]+$/u'],
            'apellido'   => ['required','string','max:100','regex:/^[\pL\s\'\-]+$/u'],
            'dni'        => ['required','string','max:20','unique:usuarios,dni','regex:/^[0-9]+$/'],
            'email'      => ['required','email:rfc','max:150','unique:usuarios,email'],
            'telefono'   => ['nullable','string','max:20','regex:/^[0-9+\-\s()]*$/'],
            'id_carrera' => 'required|exists:carreras,id_carrera',
        ], [
            'nombre.regex'   => 'El nombre no debe contener números ni símbolos.',
            'apellido.regex' => 'El apellido no debe contener números ni símbolos.',
            'dni.unique'     => 'Ya existe un usuario registrado con este número de carnet (CI/DNI).',
            'dni.regex'      => 'El CI/DNI solo debe contener números.',
            'email.email'    => 'Ingrese un email válido (sin espacios ni @ dobles).',
            'email.unique'   => 'Ya existe una cuenta con este correo electrónico.',
            'telefono.regex' => 'El teléfono solo debe contener números y símbolos (+, -).',
        ]);

        $carrera = Carrera::findOrFail($request->id_carrera);

        // Usamos el DNI como contraseÃ±a por defecto
        $password = $request->dni;
        $usuario = null;
        $estudiante = null;

        // PgBouncer (Supabase port 6543) recicla conexiones que pueden quedar en estado
        // abortado de requests anteriores. Forzamos reconexiÃ³n limpia antes de la transacciÃ³n.
        DB::reconnect();
        DB::beginTransaction();
        try {
            // 1. Crear Usuario
            $usuario = Usuario::create([
                'nombre'        => $request->nombre,
                'apellido'      => $request->apellido,
                'email'         => $request->email,
                'password_hash' => Hash::make($password), // Asegurarse de que Model use 'password_hash'
                'dni'           => $request->dni,
                'telefono'      => $request->telefono,
                'id_rol'        => 5, // Rol Estudiante
                'activo'        => true, // Como paga en caja, lo activamos directo
            ]);

            // 2. Crear Estudiante
            $legajo = 'LEG-' . now()->year . '-' . str_pad($usuario->id_usuario, 5, '0', STR_PAD_LEFT);
            $estudiante = Estudiante::create([
                'id_usuario'                => $usuario->id_usuario,
                'legajo'                    => $legajo,
                'fecha_inscripcion_inicial' => now()->toDateString(),
                'id_carrera_actual'         => $request->id_carrera,
            ]);

            // 3. Crear AfiliaciÃ³n a Carrera
            DB::table('afiliaciones_estudiante')->insert([
                'id_estudiante' => $estudiante->id_estudiante,
                'id_carrera'    => $request->id_carrera,
                'tipo_programa' => 'carrera',
                'fecha_inicio'  => now()->toDateString(),
                'estado'        => 'activo'
            ]);

            // 4. Pago de MatrÃ­cula en Efectivo (Directo)
            DB::table('matricula_unica')->insert([
                'id_estudiante' => $estudiante->id_estudiante,
                'fecha_pago'    => now()->toDateString(),
                'monto_pagado'  => 500, // Costo de matrÃ­cula (fijo 500)
                'comprobante'   => 'Pago en Efectivo - Caja',
                'estado'        => 'pagado'
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['general' => 'Error en el sistema: ' . $e->getMessage()]);
        }

        return redirect()->back()->with('credenciales', [
            'email'    => $usuario->email,
            'password' => $password,
            'legajo'   => $estudiante->legajo,
            'nombre'   => $usuario->nombre . ' ' . $usuario->apellido
        ]);
    }

    // ── CU6 (Estudiante): autoservicio — inscribirse en un grupo ──────────────
    public function inscribir(Request $request, int $idOferta)
    {
        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();
        if (!$est) abort(403);

        // Verificar cronograma de inscripción activo
        $inscripcionAbierta = DB::table('cronogramas')
            ->where('tipo_periodo', 'inscripcion')
            ->where('activo', true)
            ->whereRaw('CURRENT_DATE BETWEEN fecha_inicio AND fecha_fin')
            ->exists();

        if (!$inscripcionAbierta) {
            return back()->withErrors(['general' => 'El período de inscripciones está cerrado. Consulta el cronograma académico.']);
        }

        if (!DB::table('matricula_unica')->where('id_estudiante', $est->id_estudiante)->exists()) {
            return back()->withErrors(['general' => 'Debes pagar la matrícula antes de inscribirte.']);
        }

        if (!DB::table('afiliaciones_estudiante')->where('id_estudiante', $est->id_estudiante)->where('estado', 'activo')->exists()) {
            return back()->withErrors(['general' => 'Debes elegir un plan de pago de carrera antes de inscribirte en materias.']);
        }

        $grupo = DB::table('grupos as g')
            ->join('materias as m',          'g.id_materia', '=', 'm.id_materia')
            ->join('periodos_dictado as pd', 'g.id_periodo', '=', 'pd.id_periodo')
            ->where('g.id_oferta', $idOferta)
            ->whereRaw('g.activo IS TRUE')
            ->select('g.*', 'm.nombre as materia_nombre', 'pd.nombre as periodo_nombre', 'pd.fecha_inicio_inscripcion')
            ->first();

        if (!$grupo) abort(404);

        // Ocupación en vivo desde el inicio de la convocatoria vigente (no por
        // estado='activo'): el cupo de este mes no se libera solo porque un alumno
        // ya fue calificado mientras la inscripción de este mismo mes sigue abierta.
        $cronogramaInscripcionGlobal = DB::table('cronogramas')
            ->where('tipo_periodo', 'inscripcion')
            ->where('activo', true)
            ->whereRaw('CURRENT_DATE BETWEEN fecha_inicio AND fecha_fin')
            ->orderBy('fecha_inicio', 'desc')
            ->first();
        $ventanaInscripcionDesde = $grupo->fecha_inicio_inscripcion
            ?? ($cronogramaInscripcionGlobal ? now()->parse($cronogramaInscripcionGlobal->fecha_inicio)->toDateString() : '1900-01-01');

        $ocupadasActuales = DB::table('inscripciones')
            ->where('id_oferta', $idOferta)
            ->where('estado', '!=', 'retirado')
            ->where(function ($q) use ($ventanaInscripcionDesde) {
                $q->whereIn('estado', ['activo', 'pendiente_matricula'])
                  ->orWhereRaw('fecha_inscripcion::date >= ?', [$ventanaInscripcionDesde]);
            })
            ->count();

        if (($grupo->vacantes_max - $ocupadasActuales) <= 0) {
            return back()->withErrors(['general' => 'No hay vacantes disponibles en este grupo.']);
        }

        // ── Validar progresión secuencial de malla ────────────────────────────
        // La malla es recursiva: solo puede inscribirse en la PRIMERA materia
        // pendiente de su carrera (orden por nivel → orden_en_nivel).
        if ($est->id_carrera_actual) {
            // Malla ordenada secuencialmente
            $mallaOrdenada = DB::table('malla_curricular as mc')
                ->leftJoin('niveles_carrera as n', 'mc.id_nivel', '=', 'n.id_nivel')
                ->where('mc.id_carrera', $est->id_carrera_actual)
                ->where('mc.obligatoria', true)
                ->orderByRaw('COALESCE(n.numero_nivel, 0)')
                ->orderByRaw('COALESCE(mc.orden_en_nivel, 0)')
                ->pluck('mc.id_materia')
                ->toArray();

            // Materias ya aprobadas o con inscripción activa
            $materiasEnCurso = DB::table('inscripciones as i')
                ->join('grupos as g2', 'i.id_oferta', '=', 'g2.id_oferta')
                ->where('i.id_estudiante', $est->id_estudiante)
                ->whereIn('i.estado', ['activo', 'aprobado'])
                ->pluck('g2.id_materia')
                ->toArray();

            // Primera materia pendiente
            $proximaMateria = null;
            foreach ($mallaOrdenada as $mId) {
                if (!in_array($mId, $materiasEnCurso)) {
                    $proximaMateria = $mId;
                    break;
                }
            }

            if ($proximaMateria !== null && (int) $grupo->id_materia !== (int) $proximaMateria) {
                $nombreProxima = DB::table('materias')->where('id_materia', $proximaMateria)->value('nombre');
                return back()->withErrors([
                    'general' => "La malla es secuencial. Tu próxima materia es: {$nombreProxima}.",
                ]);
            }
        }

        // ── Solo UNA materia activa globalmente (progresión mensual) ─────────
        // Las materias son mensuales y secuenciales. El estudiante debe completar
        // la materia actual antes de inscribirse en la siguiente.
        $materiaEnCurso = DB::table('inscripciones as i')
            ->join('grupos as g2', 'i.id_oferta', '=', 'g2.id_oferta')
            ->join('materias as m2', 'g2.id_materia', '=', 'm2.id_materia')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->where('i.estado', 'activo')
            ->where('i.id_oferta', '!=', $idOferta)
            ->select('m2.nombre as materia_nombre')
            ->first();

        if ($materiaEnCurso) {
            return back()->withErrors([
                'general' => "Ya tienes '{$materiaEnCurso->materia_nombre}' en curso. Debes completarla antes de inscribirte en otra materia.",
            ]);
        }

        // Las materias son modulares (1 mes = 1 materia): si reprobó una materia cuyo
        // grupo pertenece a un período que SIGUE teniendo inscripción abierta ahora
        // mismo, sigue dentro de ese mismo mes — debe esperar a la convocatoria del
        // siguiente mes para reintentarla (no se mide por la fecha_fin larga del
        // período de dictado).
        if ($est->id_carrera_actual) {
            $idsPeriodoAbierto = $this->periodosConInscripcionAbierta($est->id_carrera_actual);

            $materiaReprobadaEsperando = DB::table('inscripciones as i')
                ->join('grupos as g2',   'i.id_oferta',   '=', 'g2.id_oferta')
                ->join('materias as m2', 'g2.id_materia', '=', 'm2.id_materia')
                ->where('i.id_estudiante', $est->id_estudiante)
                ->where('i.estado', 'reprobado')
                ->whereIn('g2.id_periodo', $idsPeriodoAbierto)
                ->select('m2.nombre as materia_nombre')
                ->first();

            if ($materiaReprobadaEsperando) {
                return back()->withErrors([
                    'general' => "Reprobaste '{$materiaReprobadaEsperando->materia_nombre}'. Debes esperar a la convocatoria de inscripción del siguiente mes para reintentarla.",
                ]);
            }
        }

        // Caso 1: inscripción en pendiente_matricula pero ya tiene pago_materia_suelta
        // (trigger corrió pero no actualizó el estado — activar manualmente)
        $inscConPagoExistente = DB::table('inscripciones as i')
            ->join('pago_materia_suelta as pms', 'i.id_inscripcion', '=', 'pms.id_inscripcion')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->where('i.id_oferta', $idOferta)
            ->where('i.estado', 'pendiente_matricula')
            ->value('i.id_inscripcion');

        if ($inscConPagoExistente) {
            DB::table('inscripciones')
                ->where('id_inscripcion', $inscConPagoExistente)
                ->update(['estado' => 'activo']);
            return redirect()->route('estudiante.materias')
                ->with('success', 'Tu pago ya estaba registrado. ¡Inscripción activada correctamente!');
        }

        // Caso 2: inscripción en pendiente_matricula con QR expirado y sin pago → limpiar
        $idsPendientesExpiradas = DB::table('inscripciones as i')
            ->join('pagofacil_transacciones as t', 'i.id_inscripcion', '=', 't.id_inscripcion')
            ->where('i.id_estudiante', $est->id_estudiante)
            ->where('i.id_oferta', $idOferta)
            ->whereIn('i.estado', ['pendiente_matricula', 'pendiente_pago'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('pago_materia_suelta')
                  ->whereColumn('pago_materia_suelta.id_inscripcion', 'i.id_inscripcion');
            })
            ->where(function ($q) {
                $q->where('t.estado', 'expirado')
                  ->orWhere(function ($q2) {
                      $q2->where('t.estado', 'pendiente')
                         ->where('t.fecha_generacion', '<', now()->subMinutes(15));
                  });
            })
            ->pluck('i.id_inscripcion');

        if ($idsPendientesExpiradas->isNotEmpty()) {
            DB::table('pagofacil_transacciones')
                ->whereIn('id_inscripcion', $idsPendientesExpiradas)
                ->where('estado', 'pendiente')
                ->update(['estado' => 'expirado']);
            DB::table('inscripciones')->whereIn('id_inscripcion', $idsPendientesExpiradas)->delete();
        }

        // Bloquear solo si hay inscripción activa o QR genuinamente en curso (<15 min)
        if (DB::table('inscripciones')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('id_oferta', $idOferta)
            ->whereIn('estado', ['pendiente_matricula', 'pendiente_pago', 'activo'])
            ->exists()) {
            return back()->withErrors(['general' => 'Ya tienes una inscripción activa o en proceso para este grupo.']);
        }

        // Verificar si tiene plan CONTADO → inscripción directa sin QR
        $tieneContado = DB::table('pago_carrera_completa')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('forma_pago', 'contado')
            ->where('estado', 'pagado')
            ->exists();

        if ($tieneContado) {
            try {
                DB::table('inscripciones')->insert([
                    'id_estudiante'    => $est->id_estudiante,
                    'id_oferta'        => $idOferta,
                    'estado'           => 'activo',
                    'fecha_inscripcion' => now(),
                ]);
                BitacoraService::registrar('inscripcion', "Inscripción directa (contado) en {$grupo->materia_nombre} ({$grupo->codigo_grupo}), estudiante #{$est->id_estudiante}");
                return redirect()->route('estudiante.materias')->with('success', '¡Inscripción exitosa en ' . $grupo->materia_nombre . '!');
            } catch (\Throwable $e) {
                return back()->withErrors(['general' => 'Error al inscribirse: ' . $e->getMessage()]);
            }
        }

        // Plan CRÉDITO: si quedan materias cubiertas por el adelanto y NO es reintento → inscripción directa
        $pagoCredito = DB::table('pago_carrera_completa')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('forma_pago', 'credito')
            ->whereIn('estado', ['parcial', 'pagado'])
            ->first();

        if ($pagoCredito) {
            $idMateria = DB::table('grupos')->where('id_oferta', $idOferta)->value('id_materia');

            // Si ya cursó esta materia antes (aprobó o reprobó) → siempre paga el reintento
            $esReintento = DB::table('consumo_materias_carrera')
                ->where('id_pago_carrera', $pagoCredito->id_pago_carrera)
                ->where('id_materia', $idMateria)
                ->exists();

            if (!$esReintento) {
                // Materias DISTINTAS ya consumidas (cada materia cuenta una sola vez)
                $materiasDistintasUsadas = DB::table('consumo_materias_carrera')
                    ->where('id_pago_carrera', $pagoCredito->id_pago_carrera)
                    ->distinct()
                    ->count('id_materia');

                if ($materiasDistintasUsadas < (int) $pagoCredito->materias_cubiertas) {
                    // Todavía cubierta por el adelanto → inscripción directa sin QR
                    try {
                        DB::table('inscripciones')->insert([
                            'id_estudiante'    => $est->id_estudiante,
                            'id_oferta'        => $idOferta,
                            'estado'           => 'activo',
                            'fecha_inscripcion' => now(),
                        ]);
                        $restantes = (int) $pagoCredito->materias_cubiertas - $materiasDistintasUsadas - 1;
                        $msg = '¡Inscripción exitosa en ' . $grupo->materia_nombre . '! (cubierta por tu adelanto'
                            . ($restantes > 0 ? ", te quedan $restantes materia(s) sin costo adicional" : '') . ')';
                        return redirect()->route('estudiante.materias')->with('success', $msg);
                    } catch (\Throwable $e) {
                        return back()->withErrors(['general' => 'Error al inscribirse: ' . $e->getMessage()]);
                    }
                }
            }
        }

        // Plan CRÉDITO (slots agotados o reintento) o MATERIA → requiere QR por materia
        $carrera    = DB::table('carreras')->where('id_carrera', $est->id_carrera_actual)->first();
        $costoTotal = (float) ($carrera?->costo_carrera_completa ?? 0);
        $totalMaterias = max(DB::table('malla_curricular')->where('id_carrera', $est->id_carrera_actual)->count(), 1);

        // Para crédito: cobrar el restante distribuido; para materia: precio estándar por materia
        $pagoC = DB::table('pago_carrera_completa')
            ->where('id_estudiante', $est->id_estudiante)
            ->whereIn('estado', ['parcial', 'pagado'])
            ->first();

        $montoMateria = $pagoC && $pagoC->forma_pago === 'credito'
            ? round(((float) $pagoC->monto_total - (float) $pagoC->monto_pagado) / $totalMaterias, 2)
            : round($costoTotal / $totalMaterias, 2);

        $montoMateria = max($montoMateria, 0.01);

        $user          = DB::table('usuarios')->where('id_usuario', $userId)->first();
        $paymentNumber = 'MATERIA-' . $est->id_estudiante . '-' . now()->timestamp;

        // Pre-crear inscripción en estado pendiente_matricula
        // El trigger actualiza a 'activo' al confirmar pago (concepto='materia')
        $idInscripcion = DB::table('inscripciones')->insertGetId([
            'id_estudiante'    => $est->id_estudiante,
            'id_oferta'        => $idOferta,
            'estado'           => 'pendiente_matricula',
            'fecha_inscripcion' => now(),
        ], 'id_inscripcion');

        // Generar QR
        try {
            $pf       = app(PagoFacilService::class);
            $qrResult = $pf->generarQR([
                'clientName'    => $user->nombre . ' ' . $user->apellido,
                'documentId'    => $user->dni ?? '00000000',
                'phoneNumber'   => $user->telefono ?: '70000000',
                'email'         => $user->email,
                'paymentNumber' => $paymentNumber,
                'clientCode'    => (string) $est->id_estudiante,
                'concepto'      => 'Materia: ' . $grupo->materia_nombre,
            ]);
        } catch (\Throwable $e) {
            DB::table('inscripciones')->where('id_inscripcion', $idInscripcion)->delete();
            return back()->withErrors(['general' => 'Error al conectar con el sistema de pago: ' . $e->getMessage()]);
        }

        $apiTransId = $qrResult['values']['transactionId'] ?? $qrResult['transactionId'] ?? null;
        $qrRaw      = $qrResult['values']['qrBase64'] ?? $qrResult['values']['qrImage'] ?? null;
        $qrImage    = $qrRaw ? (str_starts_with($qrRaw, 'data:') ? $qrRaw : 'data:image/png;base64,' . $qrRaw) : null;

        if (!$apiTransId) {
            DB::table('inscripciones')->where('id_inscripcion', $idInscripcion)->delete();
            return back()->withErrors(['general' => 'PagoFácil no devolvió ID de transacción.']);
        }

        // concepto='materia', codigo_grupo=código del grupo, id_inscripcion=FK
        // El trigger fn_confirmar_pago_qr activa la inscripción y crea pago_materia_suelta automáticamente
        $transId = DB::table('pagofacil_transacciones')->insertGetId([
            'transaction_id_api' => $apiTransId,
            'payment_number'     => $paymentNumber,
            'id_estudiante'      => $est->id_estudiante,
            'monto'              => $montoMateria,
            'concepto'           => 'materia',
            'codigo_grupo'       => $grupo->codigo_grupo,
            'id_inscripcion'     => $idInscripcion,
            'estado'             => 'pendiente',
        ], 'id_transaccion_pf');

        session(['pf_qr_ins_' . $transId => $qrImage]);

        return redirect()->route('estudiante.pago', $transId);
    }

    // ── Helper: crear afiliación tras confirmar pago de carrera ───────────────
    // Llamado desde Estudiante\PanelController::estadoPlan() al confirmarse el
    // pago de un plan de carrera vía QR.
    public function crearAfiliacion(object $trans): void
    {
        if (DB::table('afiliaciones_estudiante')->where('id_estudiante', $trans->id_estudiante)->where('estado', 'activo')->exists()) {
            return;
        }
        // codigo_grupo contiene el código de la carrera
        $idCarrera = DB::table('carreras')->where('codigo', $trans->codigo_grupo)->value('id_carrera');
        if (!$idCarrera) return;

        DB::table('afiliaciones_estudiante')->insert([
            'id_estudiante' => $trans->id_estudiante,
            'id_carrera'    => $idCarrera,
            'tipo_programa' => 'carrera',
            'fecha_inicio'  => now()->toDateString(),
            'estado'        => 'activo',
        ]);
    }

    // ── Helper: períodos de dictado de una carrera con ventana de inscripción
    // abierta ahora mismo. Misma lógica usada en resumenAcademico() — usa fechas
    // propias del período si las tiene, o cae al cronograma global de inscripción.
    private function periodosConInscripcionAbierta(int $idCarrera): \Illuminate\Support\Collection
    {
        $cronogramaGlobal = DB::table('cronogramas')
            ->where('tipo_periodo', 'inscripcion')
            ->where('activo', true)
            ->whereRaw('CURRENT_DATE BETWEEN fecha_inicio AND fecha_fin')
            ->orderBy('fecha_inicio', 'desc')
            ->first();

        $periodos = DB::table('periodos_dictado as pd')
            ->where('pd.id_carrera', $idCarrera)
            ->whereNull('pd.id_nivel')
            ->whereRaw('pd.activo IS TRUE')
            ->select('pd.id_periodo', 'pd.fecha_inicio', 'pd.fecha_fin', 'pd.fecha_inicio_inscripcion', 'pd.fecha_fin_inscripcion')
            ->get();

        return collect($periodos)->filter(function ($p) use ($cronogramaGlobal) {
            if ($p->fecha_inicio_inscripcion && $p->fecha_fin_inscripcion) {
                return now()->toDateString() >= $p->fecha_inicio_inscripcion
                    && now()->toDateString() <= $p->fecha_fin_inscripcion;
            }
            return $cronogramaGlobal !== null
                && now()->toDateString() >= $p->fecha_inicio
                && now()->toDateString() <= $p->fecha_fin;
        })->pluck('id_periodo');
    }
}
