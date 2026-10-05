<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Mail\CorreoDePrueba;
use App\Models\ConfiguracionInstitucion;
use App\Models\DocumentoRequerido;
use App\Models\Periodo;
use App\Models\User;
use App\Rules\ImagenProcesable;
use App\Rules\PdfOImagen;
use App\Support\Color;
use App\Support\CorreoDeLaInstitucion;
use App\Support\Documento;
use App\Support\Imagen;
use App\Support\InstitucionActual;
use App\Support\PoliticaDatos;
use App\Support\Reglas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Ajustes de la institucion: la marca, el limite de promotorias y que papeles se
 * piden.
 *
 * Solo administrador. No es catalogo academico —que si comparte con el
 * director—: es la identidad de toda la entidad y una regla que gobierna las
 * matriculas de todo el mundo.
 */
class ConfiguracionController extends Controller
{
    /**
     * El lado mayor de la firma, en pixeles.
     *
     * Mas ancha que una foto de perfil y por una razon concreta: una firma es
     * un trazo apaisado y fino, y reducida a 800 px de ancho los rasgos se
     * empastan al imprimirse. En el certificado ocupa unos 5 cm, asi que 1000
     * px dan holgura de sobra sin que el archivo se dispare.
     */
    private const LADO_FIRMA = 1000;

    public function mostrar(): View
    {
        return view('gestion.configuracion', [
            'institucion' => ConfiguracionInstitucion::actual(),
            // Para que la ayuda del textarea diga la verdad: el campo se ve
            // vacio en los dos casos, y «vacio» significa cosas distintas antes
            // y despues de haber escrito algo.
            'politicaEsLaDeFabrica' => PoliticaDatos::esLaDeFabrica(ConfiguracionInstitucion::actual()),
            // Para el aviso de la fecha de las alertas. Tenerla aqui y no en el
            // periodo tiene un riesgo conocido: que se quede vieja al cambiar de
            // semestre y apague las alertas sin decirlo. Eso no lo arregla el
            // esquema; lo arregla que la pantalla lo diga.
            'periodoEnCurso' => Periodo::enCurso(),
            // A cuanta gente le rompe la ficha encender «Exigir el correo».
            // Se pinta al lado del interruptor porque esa consecuencia no se
            // deduce de la palabra «obligatorio»: alcanza a quien ya esta, no
            // solo a quien se inscriba manana, y en produccion son 853 de 885.
            // Una consulta de conteo, no una lista.
            // Las cuentas no llevan el filtro de institucion (ver `User`): se
            // cuentan por `tabla()`, o la cifra sumaria las de todas.
            'sinCorreo' => InstitucionActual::tabla('users')->whereNull('email')->orWhere('email', '')->count(),
            // Si la recuperacion de contrasena funciona de verdad, y por donde.
            // Se pinta arriba del todo de la seccion porque es lo unico que hay
            // que ver de un vistazo: apagada no falla, no avisa y no se nota
            // hasta que alguien pierde su clave.
            'correoActivo' => CorreoDeLaInstitucion::hayPorDondeMandar(),
            'correoDeDonde' => CorreoDeLaInstitucion::configuradoEnLaPantalla()
                ? 'con lo que hay configurado en esta pantalla'
                : 'con lo que hay configurado en el archivo del servidor',
            // Los desactivados tambien se listan: son los que dejaron de pedirse
            // pero conservan lo entregado, y esconderlos haria creer que se
            // perdieron.
            'documentos' => DocumentoRequerido::query()
                ->withCount(['entregas as entregados' => fn ($q) => $q->where('archivo', '!=', '')])
                ->ordenados()
                ->get(),
        ]);
    }

    /**
     * Las secciones de la pantalla, cada una con su boton (05/10/2026, pedido
     * del usuario). El valor es el aviso al guardar. Los COLORES no estan:
     * tienen su propia ruta (`guardarColores`).
     */
    private const SECCIONES = [
        'marca' => 'Marca guardada.',
        'firma' => 'Firma guardada.',
        'entidad' => 'Datos de la entidad y textos legales guardados.',
        'correo' => 'Correo guardado.',
        'reglas' => 'Reglas de matrícula guardadas.',
        'alertas' => 'Alertas guardadas.',
    ];

    /** Los nombres de campo que salen en los mensajes de rechazo. */
    private const NOMBRES_DE_CAMPO = [
        'firma' => 'firma',
        'consentimiento_mayor' => 'formato de mayor de edad',
        'consentimiento_menor' => 'formato de menor de edad',
        'correo_servidor' => 'servidor de correo',
        'correo_puerto' => 'puerto',
        'correo_cifrado' => 'cifrado',
        'correo_usuario' => 'usuario del correo',
        'correo_clave' => 'contraseña del correo',
        'correo_prueba' => 'correo de prueba',
        'firmante_nombre' => 'nombre de quien firma',
        'firmante_cargo' => 'cargo de quien firma',
        'entidad_nit' => 'NIT',
        'entidad_direccion' => 'dirección',
        'entidad_correo' => 'correo de contacto',
        'entidad_telefono' => 'teléfono',
        'politica_datos' => 'texto de la política',
        'finalidad_datos' => 'finalidad del tratamiento de datos',
        'finalidad_imagen' => 'finalidad del uso de imagen',
    ];

    /**
     * Guarda UNA seccion: valida y escribe solo sus campos, y vuelve a su ancla
     * con la pagina entera ya cambiada (el formulario va con
     * `data-recarga-completa`). El rechazo vuelve tambien a su ancla y no con
     * `back()`, que dejaria la pagina arriba y el aviso sin verse: quien edita
     * no tiene que buscar donde estaba.
     *
     * Hasta el 05/10/2026 habia un solo boton para todo: probar un logo
     * obligaba a reenviar treinta campos, y el nombre o el logo nuevos no se
     * veian hasta recargar, porque viven fuera de `<main>`.
     */
    public function guardar(Request $request): RedirectResponse
    {
        $seccion = (string) $request->input('seccion');

        abort_unless(isset(self::SECCIONES[$seccion]), 404);

        $configuracion = ConfiguracionInstitucion::actual();
        $ancla = route('gestion-configuracion').'#'.$seccion;

        try {
            $datos = $request->validate(
                $this->reglasDe($seccion),
                Reglas::mensajes() + [
                    'correo_servidor.regex' => 'Escribe solo el nombre del servidor, como smtp.hostinger.com — '
                        .'sin «https://», sin barras y sin el puerto.',
                ],
                self::NOMBRES_DE_CAMPO,
            );

            match ($seccion) {
                'marca' => $this->guardarMarca($request, $datos, $configuracion),
                'firma' => $this->guardarFirma($request, $datos, $configuracion),
                'entidad' => $this->guardarEntidad($request, $datos, $configuracion),
                'correo' => $this->guardarCorreo($request, $datos, $configuracion),
                'reglas' => $this->guardarReglas($request, $datos, $configuracion),
                'alertas' => $this->guardarAlertas($request, $configuracion),
            };
        } catch (ValidationException $e) {
            // Sin la clave: un campo de contraseña con otro nombre que
            // `password` acabaria en la sesion, que en produccion es una tabla.
            return redirect()->to($ancla)
                ->withErrors($e->errors())
                ->withInput($request->except(['correo_clave']));
        }

        $configuracion->save();

        $respuesta = redirect()->to($ancla)
            ->with('success', self::SECCIONES[$seccion])
            ->with('seccion_guardada', $seccion);

        // La prueba de envio va DESPUES de guardar y en la misma peticion, no
        // en un boton aparte: asi se prueba siempre lo que se acaba de guardar.
        if ($seccion === 'correo' && $request->filled('correo_prueba')) {
            $this->probarElCorreo($request->string('correo_prueba')->toString(), $respuesta);
        }

        return $respuesta;
    }

    /**
     * Las reglas de UNA seccion. Son las mismas que habia con el boton unico,
     * repartidas: ninguna se aflojo.
     *
     * @return array<string, mixed>
     */
    private function reglasDe(string $seccion): array
    {
        return match ($seccion) {
            'marca' => [
                'nombre_institucion' => Reglas::texto(80),
                'nombre_corto' => Reglas::texto(20, obligatorio: false),
                'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenProcesable],
            ],
            'firma' => [
                'firma' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenProcesable],
                'firmante_nombre' => Reglas::texto(120, obligatorio: false),
                'firmante_cargo' => Reglas::texto(80, obligatorio: false),
            ],
            // Los cuatro datos de la entidad son OPCIONALES, y no por descuido:
            // se anadieron el 06/09/2026 a una instalacion que ya estaba
            // corriendo, y exigirlos habria dejado esta seccion imposible de
            // guardar hasta que alguien los rellenara. La pagina publica se lee
            // igual sin ellos: esconde el renglon que falta.
            'entidad' => [
                'entidad_nit' => Reglas::texto(40, obligatorio: false),
                'entidad_direccion' => Reglas::texto(160, obligatorio: false),
                'entidad_correo' => Reglas::correo(120),
                'entidad_telefono' => Reglas::telefonoDeEntidad(),
                'politica_datos' => Reglas::texto(20000, obligatorio: false),
                'finalidad_datos' => Reglas::texto(255, obligatorio: false),
                'finalidad_imagen' => Reglas::texto(255, obligatorio: false),
                'consentimiento_mayor' => ['nullable', 'file', 'max:8192', new PdfOImagen, new ImagenProcesable(puedeNoSerImagen: true)],
                'consentimiento_menor' => ['nullable', 'file', 'max:8192', new PdfOImagen, new ImagenProcesable(puedeNoSerImagen: true)],
            ],
            'correo' => [
                'correo_servidor' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
                'correo_puerto' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'correo_cifrado' => ['nullable', Rule::in(['smtps', 'smtp'])],
                'correo_usuario' => Reglas::correo(160),
                'correo_clave' => ['nullable', 'string', 'max:255'],
                'correo_prueba' => ['nullable', 'email', 'max:160'],
            ],
            'reglas' => [
                'limite_promotorias_por_periodo' => [
                    'required', 'integer', 'min:1', 'max:'.ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA,
                ],
                'promotorias_visibles_para_estudiantes' => ['nullable', 'boolean'],
            ],
            default => [
                'alerta_clase_no_dictada' => ['nullable', 'boolean'],
                'alerta_abandono' => ['nullable', 'boolean'],
                'recordar_encuesta' => ['nullable', 'boolean'],
                'correo_obligatorio' => ['nullable', 'boolean'],
                // El maximo no es capricho: una racha mas larga que el periodo
                // no se alcanza nunca y la alerta quedaria apagada sin decirlo.
                'faltas_para_abandono' => ['required', 'integer', 'min:2', 'max:20'],
                // Vacio es «sin plazo». El tope es el de un periodo largo: mas
                // alla el plazo no vence nunca y el aviso quedaria apagado.
                'dias_para_reponer' => ['nullable', 'integer', 'min:1', 'max:180'],
                'alertas_desde' => ['nullable', 'date'],
            ],
        };
    }

    /** @param array<string, mixed> $datos */
    private function guardarMarca(Request $request, array $datos, ConfiguracionInstitucion $configuracion): void
    {
        if ($request->boolean('quitar_logo') && $configuracion->logo !== '') {
            Storage::disk('local')->delete($configuracion->logo);
            $configuracion->logo = '';
        }

        if ($request->hasFile('logo')) {
            $ruta = 'institucion/logo-'.uniqid().'.webp';
            Storage::disk('local')->put($ruta, Imagen::aWebp($request->file('logo'), 320));

            if ($configuracion->logo !== '') {
                Storage::disk('local')->delete($configuracion->logo);
            }

            $configuracion->logo = $ruta;
        }

        $configuracion->nombre_institucion = $datos['nombre_institucion'];
        $configuracion->nombre_corto = trim($datos['nombre_corto'] ?? '');
    }

    /** @param array<string, mixed> $datos */
    private function guardarFirma(Request $request, array $datos, ConfiguracionInstitucion $configuracion): void
    {
        if ($request->boolean('quitar_firma') && $configuracion->firma !== '') {
            Storage::disk('local')->delete($configuracion->firma);
            $configuracion->firma = '';
        }

        if ($request->hasFile('firma')) {
            $ruta = 'institucion/firma-'.uniqid().'.png';
            Storage::disk('local')->put($ruta, Imagen::aPng($request->file('firma'), self::LADO_FIRMA));

            if ($configuracion->firma !== '') {
                Storage::disk('local')->delete($configuracion->firma);
            }

            $configuracion->firma = $ruta;
        }

        // Recortados y admiten quedarse vacios: una institucion puede tener la
        // firma escaneada antes de haber decidido como se escribe el cargo.
        $configuracion->firmante_nombre = trim($datos['firmante_nombre'] ?? '');
        $configuracion->firmante_cargo = trim($datos['firmante_cargo'] ?? '');
    }

    /** @param array<string, mixed> $datos */
    private function guardarEntidad(Request $request, array $datos, ConfiguracionInstitucion $configuracion): void
    {
        // Los dos formatos van por separado —una entidad puede tener solo el
        // de mayores—, y `guardarFormato` lanza un rechazo si una imagen no se
        // puede convertir, que vuelve a esta misma seccion.
        foreach (['mayor', 'menor'] as $version) {
            $this->guardarFormato($request, $configuracion, $version);
        }

        $configuracion->entidad_nit = trim($datos['entidad_nit'] ?? '');
        $configuracion->entidad_direccion = trim($datos['entidad_direccion'] ?? '');
        $configuracion->entidad_correo = trim($datos['entidad_correo'] ?? '');
        $configuracion->entidad_telefono = trim($datos['entidad_telefono'] ?? '');
        // Vacia se guarda como NULL: es lo que significa «publica la de
        // fabrica» (`PoliticaDatos`).
        $configuracion->politica_datos = trim($datos['politica_datos'] ?? '') ?: null;
        // Estas dos guardan '' y no null: la columna no admite nulo y su vacio
        // significa lo mismo —«usa la de fabrica»—, que resuelve el modelo.
        $configuracion->finalidad_datos = trim($datos['finalidad_datos'] ?? '');
        $configuracion->finalidad_imagen = trim($datos['finalidad_imagen'] ?? '');
    }

    /** @param array<string, mixed> $datos */
    private function guardarReglas(Request $request, array $datos, ConfiguracionInstitucion $configuracion): void
    {
        $configuracion->limite_promotorias_por_periodo = $datos['limite_promotorias_por_periodo'];
        $configuracion->promotorias_visibles_para_estudiantes = $request->boolean('promotorias_visibles_para_estudiantes');
    }

    private function guardarAlertas(Request $request, ConfiguracionInstitucion $configuracion): void
    {
        $configuracion->alerta_clase_no_dictada = $request->boolean('alerta_clase_no_dictada');
        $configuracion->alerta_abandono = $request->boolean('alerta_abandono');
        $configuracion->recordar_encuesta = $request->boolean('recordar_encuesta');
        $configuracion->correo_obligatorio = $request->boolean('correo_obligatorio');
        $configuracion->faltas_para_abandono = (int) $request->input('faltas_para_abandono');
        $configuracion->dias_para_reponer = $request->filled('dias_para_reponer')
            ? (int) $request->input('dias_para_reponer')
            : null;
        // Vacia se guarda como NULL: es lo que significa «desde el inicio del
        // periodo». Con una cadena vacia, MariaDB rechaza el INSERT con
        // «Incorrect date value» y la pantalla contesta un 500.
        $configuracion->alertas_desde = $request->input('alertas_desde') ?: null;
    }

    /**
     * Las credenciales del servidor de correo de la entidad.
     *
     * TRES COSAS QUE NO SE DEDUCEN DEL FORMULARIO:
     *
     * 1. LA CONTRASENA NO SE PUEDE LEER, solo escribir. El campo llega VACIO
     *    siempre —la plantilla no la pinta nunca— y vacio significa «deja la
     *    que hay», igual que el campo de archivo del logo. Sin esa regla, la
     *    contrasena del buzon de la entidad estaria en el codigo fuente de una
     *    pagina que abre cualquier administrador.
     *
     * 2. QUITAR EL SERVIDOR O EL USUARIO SE LLEVA LA CONTRASENA. Si no, queda
     *    una clave cifrada de un buzon que ya nadie usa, y el dia que alguien
     *    vuelva a escribir un servidor el sistema intentaria autenticarse con
     *    la contrasena vieja sin que nada lo dijera.
     *
     * 3. VACIA SE GUARDA COMO NULL Y NO COMO ''. El cast `encrypted` del modelo
     *    LANZA al intentar descifrar una cadena vacia, asi que un '' aqui no es
     *    «no hay contrasena»: es una pantalla de Institucion que revienta al
     *    abrirse. Por eso tampoco esta en el `$attributes` del modelo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarCorreo(Request $request, array $datos, ConfiguracionInstitucion $configuracion): void
    {
        $configuracion->correo_servidor = trim((string) ($datos['correo_servidor'] ?? ''));
        $configuracion->correo_usuario = trim((string) ($datos['correo_usuario'] ?? ''));

        // El puerto y el cifrado solo se pisan si vienen. Ver la validacion:
        // ausentes significa «deja lo que hay», y su valor por defecto lo pone
        // el `$attributes` del modelo.
        if (($datos['correo_puerto'] ?? null) !== null) {
            $configuracion->correo_puerto = (int) $datos['correo_puerto'];
        }

        if (($datos['correo_cifrado'] ?? null) !== null) {
            $configuracion->correo_cifrado = (string) $datos['correo_cifrado'];
        }

        $clave = (string) ($datos['correo_clave'] ?? '');

        if ($clave !== '') {
            $configuracion->correo_clave = $clave;
        }

        if ($configuracion->correo_servidor === '' || $configuracion->correo_usuario === '') {
            $configuracion->correo_clave = null;
        }
    }

    /**
     * Manda un correo de prueba y CUENTA QUE PASO.
     *
     * Es la mitad que hace util a la otra. Sin esto, un administrador escribe
     * cinco campos, guarda, ve «configuración actualizada» y se va — y si algo
     * estaba mal no se entera nadie hasta que una persona pierda su contrasena
     * y el enlace no le llegue, que es semanas despues y en otra pantalla.
     *
     * SE ENSENA EL MENSAJE DEL FALLO TAL CUAL, y es deliberado aunque suene a
     * fuga de entranas: «Authentication failed» y «Connection refused» son
     * cosas distintas que se arreglan distinto, y un «no se pudo enviar» a
     * secas deja a la persona probando al azar. Quien lo lee es un
     * administrador de la propia entidad, mirando su propia configuracion.
     */
    private function probarElCorreo(string $destino, RedirectResponse $respuesta): void
    {
        $institucion = ConfiguracionInstitucion::actual();

        // El caso que mas confunde y el unico que NO lanza: con el `.env` en
        // `log` y sin credenciales en la pantalla, el envio «funciona» y el
        // correo se queda escrito en un archivo. Sin este corte, la prueba
        // diria que salio bien y no habria salido de la maquina.
        if (! CorreoDeLaInstitucion::hayPorDondeMandar($institucion)) {
            $respuesta->with(
                'error',
                'No se envió nada: no hay servidor de correo configurado ni aquí ni en el '
                .'archivo del servidor, así que los correos solo se escriben en el registro. '
                .'Llena los campos de arriba para que la recuperación de contraseña funcione.'
            );

            return;
        }

        try {
            // Un Mailable y no un `Mail::raw()`, y el porque esta en la propia
            // clase: `MailFake::raw()` esta VACIO, asi que una prueba de este
            // boton escrita sobre `raw()` no puede afirmar que se mando nada.
            CorreoDeLaInstitucion::remitenteQueEnvia($institucion)
                ->to($destino)
                ->send(new CorreoDePrueba);

            $respuesta->with('success', "Se envió un correo de prueba a {$destino}. Si no llega en unos "
                .'minutos, mira también la carpeta de correo no deseado.');
        } catch (Throwable $e) {
            $respuesta->with(
                'error',
                'No se pudo enviar el correo de prueba. El servidor contestó: '.$e->getMessage()
            );
        }
    }

    /**
     * Guarda —o quita— el formato de autorizacion propio de una version.
     *
     * SE CONVIERTE A PDF LO QUE LLEGA COMO IMAGEN, y manda el CONTENIDO y no la
     * extension: es la misma regla que ya rige los papeles del estudiante, y
     * las dos las escribe quien sube el archivo. Un «.pdf» que en realidad es
     * una foto se convierte, y ahi es donde importa — sin esto se guardaria un
     * JPEG con nombre de PDF y la descarga lo entregaria declarado como
     * `application/pdf`, que en un celular no abre y no dice por que.
     *
     * SI LA CONVERSION FALLA NO SE GUARDA NADA, y aqui va al reves que en los
     * papeles del estudiante —donde un papel raro vale mas entregado que
     * perdido—. Este archivo no es evidencia de nadie: es la plantilla que va a
     * bajarse todo el mundo, la sube un administrador que esta mirando la
     * pantalla, y guardarla rota deja a la entidad entera sin formato. Mejor
     * que se entere ahora.
     */
    private function guardarFormato(Request $request, ConfiguracionInstitucion $configuracion, string $version): void
    {
        $campo = 'consentimiento_'.$version;
        $actual = (string) $configuracion->{$campo};

        if ($request->boolean('quitar_'.$campo) && $actual !== '') {
            Storage::disk('local')->delete($actual);
            $configuracion->{$campo} = '';

            return;
        }

        if (! $request->hasFile($campo)) {
            return;
        }

        $archivo = $request->file($campo);
        $binario = (string) file_get_contents($archivo->getRealPath());

        if (Documento::esImagen($binario)) {
            try {
                // La ruta original y no solo los bytes: ahi vive el EXIF con la
                // orientacion, y sin aplicarla un formato fotografiado en
                // vertical se guarda tumbado, sin fallar y sin avisar.
                $binario = Documento::aPdf($binario, $archivo->getRealPath());
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    $campo => 'No se pudo convertir esa imagen a PDF. Intenta con el archivo en PDF.',
                ]);
            }
        }

        $ruta = 'institucion/consentimiento-'.$version.'-'.uniqid().'.pdf';
        Storage::disk('local')->put($ruta, $binario);

        if ($actual !== '') {
            Storage::disk('local')->delete($actual);
        }

        $configuracion->{$campo} = $ruta;
    }

    /**
     * LOS COLORES, CON SU PROPIO BOTON (05/10/2026, pedido del usuario: «la
     * seccion de colores deberia tener su propio boton de guardar y que apenas
     * se guarde todo cambie de color»).
     *
     * Aparte del formulario general por dos razones. La primera es la pedida:
     * probar un color no obliga a revisar treinta campos. La segunda es por
     * que «no cambiaba»: los colores viven en el `<style>` del `<head>` y en el
     * `<header>`, FUERA de `<main>`, y `acciones.js` solo repinta `<main>`. El
     * formulario de colores va con `data-recarga-completa`, asi que guardar
     * NAVEGA y la pagina entera llega ya pintada; vuelve a `#colores` para que
     * quien guardo vea el resultado donde estaba.
     */
    public function guardarColores(Request $request): RedirectResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        // El rechazo vuelve a `#colores` y no con `back()`: con la recarga
        // completa, `back()` dejaria la pagina arriba y el aviso a media
        // pantalla, sin verse. Lleva lo elegido para no perderlo.
        try {
            $datos = $request->validate([
                'color_acento' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
                // FONDO Y CABECERA (05/10/2026). Al contrario que el acento, el
                // contraste aqui BLOQUEA: un fondo oscuro deja ilegible el texto de
                // todas las pantallas, y eso no lo pide ninguna marca. Con la
                // casilla «el de fabrica» marcada no se mira el color.
                'color_fondo' => [
                    'nullable', 'regex:/^#[0-9a-fA-F]{6}$/',
                    function (string $campo, mixed $valor, \Closure $falla) use ($request) {
                        if ($request->boolean('color_fondo_fabrica') || ! is_string($valor) || ! Color::esHexValido($valor)) {
                            return;
                        }
                        $razon = Color::contraste(Color::TINTA_SUAVE, $valor);
                        if ($razon < Color::CONTRASTE_MINIMO_ACENTO) {
                            $falla('Ese fondo es demasiado oscuro: el texto gris de las pantallas quedaría en '
                                .number_format($razon, 1, ',', '').':1 de contraste y el mínimo es 4,5:1. Elige un tono más claro.');
                        }
                    },
                ],
                'color_cabecera' => [
                    'nullable', 'regex:/^#[0-9a-fA-F]{6}$/',
                    function (string $campo, mixed $valor, \Closure $falla) use ($request) {
                        if ($request->boolean('color_cabecera_fabrica') || ! is_string($valor) || ! Color::esHexValido($valor)) {
                            return;
                        }
                        $razon = Color::contraste(Color::textoSobre($valor), $valor);
                        if ($razon < Color::CONTRASTE_MINIMO_ACENTO) {
                            $falla('Con ese color de cabecera ni el texto blanco ni el oscuro llegan a 4,5:1 (el mejor queda en '
                                .number_format($razon, 1, ',', '').':1). Elige un tono más claro o más oscuro.');
                        }
                    },
                ],
            ], [
                'color_acento.regex' => 'El color de acento debe ir en formato #rrggbb.',
                'color_fondo.regex' => 'El color de fondo debe ir en formato #rrggbb.',
                'color_cabecera.regex' => 'El color de la cabecera debe ir en formato #rrggbb.',
            ]);
        } catch (ValidationException $e) {
            return redirect()->to(route('gestion-configuracion').'#colores')
                ->withErrors($e->errors())
                ->withInput($request->only(['color_acento', 'color_fondo', 'color_cabecera', 'color_fondo_fabrica', 'color_cabecera_fabrica']));
        }

        $configuracion->color_acento = strtolower($datos['color_acento']);
        // '' es «el de fabrica»: con la casilla marcada, o sin color enviado.
        $configuracion->color_fondo = $request->boolean('color_fondo_fabrica') ? '' : strtolower((string) ($datos['color_fondo'] ?? ''));
        $configuracion->color_cabecera = $request->boolean('color_cabecera_fabrica') ? '' : strtolower((string) ($datos['color_cabecera'] ?? ''));
        $configuracion->save();

        $respuesta = redirect()->to(route('gestion-configuracion').'#colores')
            ->with('success', 'Colores guardados.');

        // El contraste del ACENTO no bloquea: una marca clara puede ser
        // legitima, pero el texto blanco de los botones deja de leerse y hay
        // que avisarlo. (El del fondo y la cabecera si bloquea; ver arriba.)
        $razon = $configuracion->contraste_texto_boton;

        if ($razon < 4.5) {
            $respuesta->with(
                'error',
                'Ojo: el texto blanco sobre ese color de acento queda en '
                .number_format($razon, 1).':1 de contraste, por debajo del mínimo de 4.5:1. '
                .'Los botones serán difíciles de leer; considera un tono más oscuro.'
            );
        }

        return $respuesta;
    }

    /** Agrega un papel a la lista de los que se piden. */
    public function documentoNuevo(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => Reglas::texto(60),
            'descripcion' => Reglas::texto(120, obligatorio: false),
            'obligatorio' => ['nullable', 'boolean'],
            'orden' => ['required', 'integer', 'min:0'],
        ], Reglas::mensajes());

        // `activo` no se pide: un documento se crea pidiendose. Dejar de pedirlo
        // es una accion aparte en la lista, y no una casilla que se pueda
        // desmarcar sin darse cuenta mientras se escribe el nombre.
        $documento = DocumentoRequerido::create([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? '',
            'obligatorio' => $request->boolean('obligatorio'),
            'orden' => $datos['orden'],
            'activo' => true,
        ]);

        return redirect()->route('gestion-configuracion')
            ->with('success', "«{$documento->nombre}» ya se le pide a los estudiantes.");
    }

    /**
     * Deja de pedir un papel, o vuelve a pedirlo.
     *
     * No lo borra a proposito. Los archivos que ya subieron los estudiantes
     * cuelgan del requisito: borrarlo se llevaria por delante la prueba de que
     * en su momento cumplieron, y eso no se puede deshacer.
     */
    public function documentoAlternar(DocumentoRequerido $documento): RedirectResponse
    {
        $documento->activo = ! $documento->activo;
        $documento->save();

        return redirect()->route('gestion-configuracion')->with(
            'success',
            $documento->activo
                ? "«{$documento->nombre}» vuelve a pedirse."
                : "«{$documento->nombre}» deja de pedirse. Lo ya entregado se conserva."
        );
    }
}
