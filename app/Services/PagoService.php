<?php

namespace App\Services;

use App\Models\Carrera;
use App\Models\Estudiante;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class PagoService
{
    public function __construct(private InscripcionService $inscripciones)
    {
    }

    // ── CU7 (Secretaria): listado con resumen de estado de pagos ─────────────
    public function index(Request $request)
    {
        $buscar = $request->get('buscar', '');

        $tieneMatricula = Schema::hasTable('matricula_unica');
        $tieneCarrera   = Schema::hasTable('pago_carrera_completa');

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

        $usuarios = $query->get();

        // Cargar matrÃ­culas y planes en batch para evitar N+1
        $matriculas  = collect();
        $planesCarrera = collect();

        if ($usuarios->count()) {
            $idsEst = $usuarios->map(fn($u) => $u->estudiante?->id_estudiante)->filter()->values();

            if ($tieneMatricula && $idsEst->count()) {
                $matriculas = DB::table('matricula_unica')
                    ->whereIn('id_estudiante', $idsEst)
                    ->get()->keyBy('id_estudiante');
            }
            if ($tieneCarrera && $idsEst->count()) {
                $planesCarrera = DB::table('pago_carrera_completa')
                    ->whereIn('id_estudiante', $idsEst)
                    ->orderByDesc('fecha_contrato')
                    ->get()->groupBy('id_estudiante');
            }
        }

        $estudiantes = $usuarios->map(function ($u) use ($matriculas, $planesCarrera) {
            $idEst = $u->estudiante?->id_estudiante;
            $mat   = $idEst ? $matriculas->get($idEst) : null;
            $plan  = $idEst ? $planesCarrera->get($idEst)?->first() : null;

            return [
                'id_usuario'      => $u->id_usuario,
                'nombre'          => $u->nombre,
                'apellido'        => $u->apellido,
                'email'           => $u->email,
                'dni'             => $u->dni,
                'activo'          => $u->activo,
                'legajo'          => $u->estudiante?->legajo,
                'tiene_matricula' => $mat !== null,
                'matricula_estado'=> $mat?->estado,
                'tiene_carrera'   => $plan !== null,
                'carrera_estado'  => $plan?->estado,
                'carrera_forma'   => $plan?->forma_pago,
            ];
        });

        return Inertia::render('Secretaria/CU7Pagos/Index', [
            'estudiantes' => $estudiantes,
            'filtros'     => ['buscar' => $buscar],
        ]);
    }

    // ── CU7 (Secretaria): detalle completo de pagos de un estudiante ─────────
    public function show(int $id)
    {
        $usuario    = Usuario::where('id_usuario', $id)->where('id_rol', 5)->firstOrFail();
        $estudiante = Estudiante::where('id_usuario', $id)->first();
        $idEst      = $estudiante?->id_estudiante;

        // Carrera actual del estudiante
        $carreraActual = null;
        if ($estudiante?->id_carrera_actual) {
            $c = Carrera::find($estudiante->id_carrera_actual);
            if ($c) {
                $carreraActual = [
                    'id_carrera'             => $c->id_carrera,
                    'codigo'                 => $c->codigo,
                    'nombre'                 => $c->nombre,
                    'costo_carrera_completa' => (float) $c->costo_carrera_completa,
                ];
            }
        }

        // MatrÃ­cula
        $matricula = null;
        if ($idEst && Schema::hasTable('matricula_unica')) {
            $m = DB::table('matricula_unica')->where('id_estudiante', $idEst)->first();
            $matricula = $m ? (array) $m : null;
        }

        // Plan de carrera + cuotas
        $planCarrera = null;
        $cuotas      = [];
        if ($idEst && Schema::hasTable('pago_carrera_completa')) {
            $plan = DB::table('pago_carrera_completa')
                ->where('id_estudiante', $idEst)
                ->orderByDesc('fecha_contrato')
                ->first();

            if ($plan) {
                $planCarrera = (array) $plan;

                // Nombre de la carrera del plan
                if (isset($plan->id_carrera)) {
                    $cPlan = Carrera::find($plan->id_carrera);
                    $planCarrera['carrera_nombre'] = $cPlan?->nombre;
                    $planCarrera['carrera_codigo'] = $cPlan?->codigo;
                }

                if (Schema::hasTable('cuotas_carrera') && isset($plan->id_pago_carrera)) {
                    $cuotas = DB::table('cuotas_carrera')
                        ->where('id_pago_carrera', $plan->id_pago_carrera)
                        ->orderBy('numero_cuota')
                        ->get()
                        ->map(fn($c) => (array) $c)
                        ->toArray();
                }
            }
        }

        // Materias sueltas (disponible cuando CU6 estÃ© implementado)
        $materiasSueltas = [];

        // Carreras activas para el select del modal
        $carrerasDisponibles = Carrera::whereRaw('activo IS TRUE')
            ->orderBy('nombre')
            ->get()
            ->map(fn($c) => [
                'id_carrera'             => $c->id_carrera,
                'codigo'                 => $c->codigo,
                'nombre'                 => $c->nombre,
                'costo_carrera_completa' => (float) $c->costo_carrera_completa,
                'minimo_30'              => round((float) $c->costo_carrera_completa * 0.30, 2),
                'precio_contado'         => round((float) $c->costo_carrera_completa * 0.80, 2),
            ]);

        return Inertia::render('Secretaria/CU7Pagos/Show', [
            'estudiante' => [
                'id_usuario'    => $usuario->id_usuario,
                'id_estudiante' => $idEst,
                'nombre'        => $usuario->nombre,
                'apellido'      => $usuario->apellido,
                'email'         => $usuario->email,
                'dni'           => $usuario->dni,
                'activo'        => $usuario->activo,
                'legajo'        => $estudiante?->legajo,
            ],
            'carreraActual'       => $carreraActual,
            'matricula'           => $matricula,
            'planCarrera'         => $planCarrera,
            'cuotas'              => $cuotas,
            'materiasSueltas'     => $materiasSueltas,
            'carrerasDisponibles' => $carrerasDisponibles,
            'pendiente' => [
                'matricula' => !Schema::hasTable('matricula_unica'),
                'carrera'   => !Schema::hasTable('pago_carrera_completa'),
                'materias'  => !Schema::hasTable('inscripciones'),
            ],
        ]);
    }

    // ── CU7 (Secretaria): registro admin directo de matrícula (sin QR) ───────
    public function registrarMatricula(Request $request, int $id)
    {
        $request->validate([
            'monto'       => 'required|numeric|min:1',
            'comprobante' => 'nullable|string|max:120',
        ]);

        $estudiante = Estudiante::where('id_usuario', $id)->firstOrFail();

        if (DB::table('matricula_unica')->where('id_estudiante', $estudiante->id_estudiante)->exists()) {
            return back()->withErrors(['monto' => 'El estudiante ya tiene matrÃ­cula registrada.']);
        }

        $comprobante = $request->comprobante
            ?: ('ADM-MAT-' . $estudiante->id_estudiante . '-' . now()->timestamp);

        DB::table('matricula_unica')->insert([
            'id_estudiante' => $estudiante->id_estudiante,
            'monto_pagado'  => $request->monto,
            'comprobante'   => $comprobante,
            'estado'        => 'pagado',
        ]);

        BitacoraService::registrar('matricula_manual', "Matrícula registrada manualmente (caja): estudiante #{$estudiante->id_estudiante}, monto {$request->monto}");

        return back()->with('success', 'MatrÃ­cula registrada correctamente.');
    }

    // ── CU7 (Secretaria): registro admin directo de plan de carrera (sin QR) ──
    public function registrarCarrera(Request $request, int $id)
    {
        $request->validate([
            'id_carrera' => 'required|integer|exists:carreras,id_carrera',
            'monto'      => 'required|numeric|min:0.01',
        ]);

        $estudiante = Estudiante::where('id_usuario', $id)->firstOrFail();
        $carrera    = Carrera::findOrFail($request->id_carrera);
        $costo      = (float) $carrera->costo_carrera_completa;
        $monto      = (float) $request->monto;

        $minimo30 = round($costo * 0.30, 2);
        if ($monto < $minimo30) {
            return back()->withErrors([
                'monto' => "El monto mÃ­nimo es el 30% del costo: Bs. {$minimo30}.",
            ]);
        }

        if (DB::table('pago_carrera_completa')
            ->where('id_estudiante', $estudiante->id_estudiante)
            ->where('id_carrera', $carrera->id_carrera)
            ->exists()) {
            return back()->withErrors(['id_carrera' => 'El estudiante ya tiene un plan para esta carrera.']);
        }

        $precioContado = round($costo * 0.80, 2);
        $formaPago     = $monto >= $precioContado ? 'contado' : 'credito';
        $estado        = $formaPago === 'contado'  ? 'pagado'  : 'parcial';

        // El trigger 'crear_cuotas_credito' genera las cuotas automÃ¡ticamente al insertar
        DB::table('pago_carrera_completa')->insert([
            'id_estudiante'  => $estudiante->id_estudiante,
            'id_carrera'     => $carrera->id_carrera,
            'monto_total'    => $costo,
            'monto_pagado'   => $monto,
            'forma_pago'     => $formaPago,
            'estado'         => $estado,
            'fecha_contrato' => now()->toDateString(),
        ]);

        $estudiante->update(['id_carrera_actual' => $carrera->id_carrera]);

        BitacoraService::registrar('plan_carrera_manual', "Plan de carrera registrado manualmente (caja): estudiante #{$estudiante->id_estudiante}, carrera #{$carrera->id_carrera}, forma {$formaPago}, monto {$monto}");

        $label = $formaPago === 'contado' ? 'contado (con descuento del 20%)' : 'crÃ©dito';
        return back()->with('success', "Plan de carrera registrado a {$label}.");
    }

    // ── CU7 (Secretaria): marcar una cuota como pagada (admin directo) ───────
    public function pagarCuota(int $idPago, int $numCuota)
    {
        $afectadas = DB::table('cuotas_carrera')
            ->where('id_pago_carrera', $idPago)
            ->where('numero_cuota', $numCuota)
            ->where('estado', 'pendiente')
            ->update([
                'estado'     => 'pagado',
                'fecha_pago' => now()->toDateString(),
            ]);

        if (!$afectadas) {
            return back()->withErrors(['cuota' => 'La cuota no existe o ya fue pagada.']);
        }

        // Revisar si quedan cuotas pendientes
        $restantes = DB::table('cuotas_carrera')
            ->where('id_pago_carrera', $idPago)
            ->where('estado', 'pendiente')
            ->count();

        DB::table('pago_carrera_completa')
            ->where('id_pago_carrera', $idPago)
            ->update(['estado' => $restantes === 0 ? 'pagado' : 'parcial']);

        BitacoraService::registrar('cuota_pagada_manual', "Cuota #{$numCuota} del pago #{$idPago} registrada como pagada manualmente (caja)");

        return back()->with('success', "Cuota #{$numCuota} registrada como pagada.");
    }

    // ── CU7 (Estudiante): elegir plan de pago de carrera ─────────────────────
    // tipos: 'contado' (20% desc) | 'credito' (30% enganche) | 'materia' (sin enganche)
    public function elegirPlan(Request $request, string $tipo)
    {
        if (!in_array($tipo, ['contado', 'credito', 'materia'])) abort(404);

        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();
        if (!$est || !$est->id_carrera_actual) abort(403);

        if (DB::table('afiliaciones_estudiante')->where('id_estudiante', $est->id_estudiante)->where('estado', 'activo')->exists()) {
            return back()->withErrors(['plan' => 'Ya tienes un plan de carrera activo.']);
        }

        $carrera    = DB::table('carreras')->where('id_carrera', $est->id_carrera_actual)->firstOrFail();
        $costoTotal = (float) $carrera->costo_carrera_completa;

        // Expirar cualquier QR de carrera anterior pendiente (permite reintentar sin bloqueo)
        DB::table('pagofacil_transacciones')
            ->where('id_estudiante', $est->id_estudiante)
            ->where('concepto', 'carrera')
            ->where('estado', 'pendiente')
            ->update(['estado' => 'expirado']);

        // Plan POR MATERIA: sin QR, crear afiliación directamente (sin pago_carrera_completa)
        if ($tipo === 'materia') {
            DB::table('afiliaciones_estudiante')->insert([
                'id_estudiante' => $est->id_estudiante,
                'id_carrera'    => $est->id_carrera_actual,
                'tipo_programa' => 'carrera',
                'fecha_inicio'  => now()->toDateString(),
                'estado'        => 'activo',
            ]);
            BitacoraService::registrar('plan_carrera', "Plan elegido: materia (pago por materia), estudiante #{$est->id_estudiante}");
            return redirect()->route('estudiante.materias')->with('success', '¡Plan activado! Ya puedes inscribirte en materias. Pagas cada materia al inscribirte.');
        }

        // CONTADO (≥80% = con 20% descuento) o CRÉDITO (≥30% = enganche)
        // El trigger detecta contado vs credito según monto vs costo*0.80
        $monto = match ($tipo) {
            'contado' => round($costoTotal * 0.80, 2),
            'credito' => round($costoTotal * 0.30, 2),
        };

        $user          = DB::table('usuarios')->where('id_usuario', $userId)->first();
        $paymentNumber = 'CARRERA-' . $est->id_estudiante . '-' . now()->timestamp;

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
                'concepto'      => 'Carrera: ' . $carrera->nombre,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['plan' => 'Error al conectar con el sistema de pago: ' . $e->getMessage()]);
        }

        $apiTransId = $qrResult['values']['transactionId'] ?? $qrResult['transactionId'] ?? null;
        $qrRaw      = $qrResult['values']['qrBase64'] ?? $qrResult['values']['qrImage'] ?? null;
        $qrImage    = $qrRaw ? (str_starts_with($qrRaw, 'data:') ? $qrRaw : 'data:image/png;base64,' . $qrRaw) : null;

        if (!$apiTransId) {
            return back()->withErrors(['plan' => 'PagoFácil no devolvió ID de transacción.']);
        }

        // El trigger fn_confirmar_pago_qr (concepto='carrera') crea pago_carrera_completa automáticamente.
        // Solo insertamos en pagofacil_transacciones. codigo_grupo = código de la carrera.
        $transId = DB::table('pagofacil_transacciones')->insertGetId([
            'transaction_id_api' => $apiTransId,
            'payment_number'     => $paymentNumber,
            'id_estudiante'      => $est->id_estudiante,
            'monto'              => $monto,
            'concepto'           => 'carrera',
            'codigo_grupo'       => $carrera->codigo,
            'estado'             => 'pendiente',
        ], 'id_transaccion_pf');

        session(['pf_qr_pc_' . $transId => $qrImage]);

        return redirect()->route('estudiante.pago.carrera', $transId);
    }

    // ── CU7 (Estudiante): página de pago del plan de carrera ─────────────────
    public function pagoCarrera(int $transId)
    {
        $trans = DB::table('pagofacil_transacciones')->where('id_transaccion_pf', $transId)->first();
        if (!$trans) abort(404);

        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();
        if (!$est || (int) $trans->id_estudiante !== (int) $est->id_estudiante) abort(403);

        $costoTotal  = (float) DB::table('carreras')->where('codigo', $trans->codigo_grupo)->value('costo_carrera_completa');
        $esContado   = $costoTotal > 0 && $trans->monto >= round($costoTotal * 0.80, 2);
        $planNombre  = $esContado ? 'Pago Total (20% descuento)' : 'Enganche 30% + materias';

        return Inertia::render('Estudiante/PagoCarrera', [
            'transId'    => $transId,
            'qrImage'    => session('pf_qr_pc_' . $transId),
            'estado'     => $trans->estado,
            'planNombre' => $planNombre,
            'monto'      => (float) $trans->monto,
        ]);
    }

    // ── CU7 (Estudiante): polling del estado del plan de carrera ─────────────
    public function estadoPlan(int $transId)
    {
        $trans = DB::table('pagofacil_transacciones')->where('id_transaccion_pf', $transId)->first();
        if (!$trans) return response()->json(['estado' => 'error'], 404);

        if ($trans->estado === 'pagado') return response()->json(['estado' => 'pagado']);

        if ($trans->fecha_generacion && now()->diffInMinutes($trans->fecha_generacion) >= 15) {
            DB::table('pagofacil_transacciones')
                ->where('id_transaccion_pf', $transId)->where('estado', 'pendiente')
                ->update(['estado' => 'expirado']);
            return response()->json(['estado' => 'expirado']);
        }

        if ($trans->transaction_id_api) {
            try {
                $result = app(PagoFacilService::class)->consultarTransaccion((int) $trans->transaction_id_api);
                if (PagoFacilService::esPagado($result)) {
                    // El UPDATE dispara el trigger que crea pago_carrera_completa
                    DB::table('pagofacil_transacciones')
                        ->where('id_transaccion_pf', $transId)->where('estado', 'pendiente')
                        ->update(['estado' => 'pagado']);
                    $this->inscripciones->crearAfiliacion($trans);
                    BitacoraService::registrar('pago_confirmado', "Pago confirmado (polling): transacción #{$transId}, concepto: carrera, estudiante #{$trans->id_estudiante}", $trans->id_estudiante);
                    return response()->json(['estado' => 'pagado']);
                }
            } catch (\Throwable) {}
        }

        return response()->json(['estado' => $trans->estado ?? 'pendiente']);
    }

    // ── CU7 (Estudiante): página de pago de materia ──────────────────────────
    public function pagoInscripcion(int $transId)
    {
        $trans = DB::table('pagofacil_transacciones')->where('id_transaccion_pf', $transId)->first();
        if (!$trans) abort(404);

        $userId = auth()->id();
        $est    = DB::table('estudiantes')->where('id_usuario', $userId)->first();
        if (!$est || (int) $trans->id_estudiante !== (int) $est->id_estudiante) abort(403);

        $concepto = '';
        if ($trans->id_inscripcion) {
            $row = DB::table('inscripciones as i')
                ->join('grupos as g',           'i.id_oferta',   '=', 'g.id_oferta')
                ->join('materias as m',          'g.id_materia',  '=', 'm.id_materia')
                ->join('periodos_dictado as pd', 'g.id_periodo',  '=', 'pd.id_periodo')
                ->where('i.id_inscripcion', $trans->id_inscripcion)
                ->select('m.nombre as materia_nombre', 'pd.nombre as periodo_nombre')
                ->first();
            if ($row) $concepto = $row->materia_nombre . ' — ' . $row->periodo_nombre;
        }

        return Inertia::render('Estudiante/PagoInscripcion', [
            'transId'  => $transId,
            'qrImage'  => session('pf_qr_ins_' . $transId),
            'estado'   => $trans->estado,
            'concepto' => $concepto,
            'monto'    => (float) $trans->monto,
        ]);
    }

    // ── CU7 (Estudiante): polling del estado de pago de materia ──────────────
    public function estadoInscripcion(int $transId)
    {
        $trans = DB::table('pagofacil_transacciones')->where('id_transaccion_pf', $transId)->first();
        if (!$trans) return response()->json(['estado' => 'error'], 404);

        if ($trans->estado === 'pagado') return response()->json(['estado' => 'pagado']);

        if ($trans->fecha_generacion && now()->diffInMinutes($trans->fecha_generacion) >= 15) {
            DB::table('pagofacil_transacciones')
                ->where('id_transaccion_pf', $transId)->where('estado', 'pendiente')
                ->update(['estado' => 'expirado']);
            // Limpiar inscripción pendiente
            if ($trans->id_inscripcion) {
                DB::table('inscripciones')
                    ->where('id_inscripcion', $trans->id_inscripcion)
                    ->where('estado', 'pendiente_matricula')
                    ->delete();
            }
            return response()->json(['estado' => 'expirado']);
        }

        if ($trans->transaction_id_api) {
            try {
                $result = app(PagoFacilService::class)->consultarTransaccion((int) $trans->transaction_id_api);
                if (PagoFacilService::esPagado($result)) {
                    // UPDATE dispara trigger fn_confirmar_pago_qr (concepto='materia')
                    // El trigger activa la inscripción y crea pago_materia_suelta automáticamente
                    DB::table('pagofacil_transacciones')
                        ->where('id_transaccion_pf', $transId)->where('estado', 'pendiente')
                        ->update(['estado' => 'pagado']);
                    BitacoraService::registrar('pago_confirmado', "Pago confirmado (polling): transacción #{$transId}, concepto: materia, estudiante #{$trans->id_estudiante}", $trans->id_estudiante);
                    return response()->json(['estado' => 'pagado']);
                }
            } catch (\Throwable) {}
        }

        return response()->json(['estado' => $trans->estado ?? 'pendiente']);
    }
}
