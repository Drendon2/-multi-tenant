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

    public function guardar(Request $request): RedirectResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        $datos = $request->validate([
            'nombre_institucion' => Reglas::texto(80),
            // Los cuatro datos de la entidad son OPCIONALES, y no por descuido:
            // se anadieron el 06/09/2026 a una instalacion que ya estaba
            // corriendo, y exigirlos habria dejado esta pantalla imposible de
            // guardar —para cambiar el color de acento, por ejemplo— hasta que
            // alguien los rellenara. La pagina publica se lee igual sin ellos:
            // esconde el renglon que falta.
            'entidad_nit' => Reglas::texto(40, obligatorio: false),
            'entidad_direccion' => Reglas::texto(160, obligatorio: false),
            'entidad_correo' => Reglas::correo(120),
            // El telefono de la ENTIDAD no lleva la regla de los diez digitos
            // que llevan las personas, y el porque esta en `Reglas`: aqui va un
            // fijo con extension o dos numeros, no un celular.
            'entidad_telefono' => Reglas::telefonoDeEntidad(),
            // El tope es enorme a proposito. La linea que habia aqui decia «sin
            // tope de largo: es un texto legal», y eso sigue siendo verdad —lo
            // que no puede seguir siendo verdad es que un campo acepte lo que
            // le echen: sin ningun maximo esto es una via de escribir megabytes
            // en la base por peticion. Veinte mil caracteres son unas diez
            // paginas, muchisimo mas de lo que ocupa el texto de fabrica, y
            // caben de sobra en la columna TEXT (65.535 BYTES, que con tildes
            // no son 65.535 caracteres).
            'politica_datos' => Reglas::texto(20000, obligatorio: false),
            // Las dos finalidades SI llevan tope corto: son una frase que se
            // incrusta dentro de otra, en la politica y en el papel que se
            // firma. Un parrafo entero ahi rompe las dos.
            'finalidad_datos' => Reglas::texto(255, obligatorio: false),
            'finalidad_imagen' => Reglas::texto(255, obligatorio: false),
            // Los dos formatos de autorizacion que puede subir la entidad.
            //
            // Se admite PDF Y TAMBIEN imagen, y no es una comodidad: hay
            // entidades cuyo formato aprobado existe solo en papel, y lo que
            // tienen es la foto del escaneo. Lo que llega como imagen se
            // convierte a PDF antes de guardarse, igual que los papeles que
            // sube el estudiante, asi que en disco solo hay PDF y quien lo baje
            // lo abre igual.
            //
            // El tope de 8 MB es el mismo que el de los papeles del estudiante
            // y por lo mismo: es una foto de celular lo que puede llegar.
            //
            // `PdfOImagen` mira el CONTENIDO y no la extension. `mimes:` haria
            // lo mismo en produccion y NO en las pruebas —ahi `UploadedFile`
            // se cree el tipo que declara el nombre del archivo—, asi que una
            // prueba de rechazo por ese camino pasaria en verde con la regla
            // quitada. El porque entero esta en la regla.
            'consentimiento_mayor' => ['nullable', 'file', 'max:8192', new PdfOImagen, new ImagenProcesable(puedeNoSerImagen: true)],
            'consentimiento_menor' => ['nullable', 'file', 'max:8192', new PdfOImagen, new ImagenProcesable(puedeNoSerImagen: true)],
            // El servidor de correo de la entidad. Los cinco son OPCIONALES: sin
            // ellos manda lo del `.env`, que es como funcionaba antes.
            //
            // El servidor va con lista blanca de nombre de maquina —letras,
            // digitos, puntos y guiones— y no como texto libre. Dos razones: un
            // «https://smtp...» pegado del panel del proveedor no es un nombre
            // de maquina y fallaria luego sin decir por que, y esto acaba
            // siendo una conexion de salida que abre el servidor, asi que
            // cuanto menos quepa ahi, mejor.
            'correo_servidor' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            // Estos dos son OPCIONALES aunque el formulario los mande siempre, y
            // no es dejadez: `required` aqui obliga a que TODO guardado de esta
            // pantalla los traiga, y lo primero que rompe es cualquier
            // guardado que no venga de este formulario. Ausentes significa
            // «deja lo que hay», que es lo unico que puede significar.
            'correo_puerto' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'correo_cifrado' => ['nullable', Rule::in(['smtps', 'smtp'])],
            // El usuario ES la direccion del buzon, y ademas es el «De:» de lo
            // que salga. Por eso se valida como correo y no como texto.
            'correo_usuario' => Reglas::correo(160),
            'correo_clave' => ['nullable', 'string', 'max:255'],
            // A donde se manda la prueba. Solo se mira si se pulso el boton de
            // probar, pero la regla va siempre: un campo que se valida a veces
            // es un campo que un dia se guarda sin validar.
            'correo_prueba' => ['nullable', 'email', 'max:160'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenProcesable],
            'firma' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenProcesable],
            'firmante_nombre' => Reglas::texto(120, obligatorio: false),
            'firmante_cargo' => Reglas::texto(80, obligatorio: false),
            'color_acento' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'limite_promotorias_por_periodo' => [
                'required', 'integer', 'min:1', 'max:'.ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA,
            ],
            'promotorias_visibles_para_estudiantes' => ['nullable', 'boolean'],
            'alerta_clase_no_dictada' => ['nullable', 'boolean'],
            'alerta_abandono' => ['nullable', 'boolean'],
            'recordar_encuesta' => ['nullable', 'boolean'],
            'correo_obligatorio' => ['nullable', 'boolean'],
            // El maximo no es capricho: una racha mas larga que el periodo no
            // se alcanza nunca y la alerta quedaria apagada sin decirlo.
            'faltas_para_abandono' => ['required', 'integer', 'min:2', 'max:20'],
            'alertas_desde' => ['nullable', 'date'],
        ], Reglas::mensajes() + [
            'color_acento.regex' => 'El color de acento debe ir en formato #rrggbb.',
            // El de Laravel para `regex` es «El formato de servidor de correo no
            // es válido», que no le dice a nadie que sobra el «https://».
            'correo_servidor.regex' => 'Escribe solo el nombre del servidor, como smtp.hostinger.com — '
                .'sin «https://», sin barras y sin el puerto.',
        ], [
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
        ]);

        // Quitar el logo es una casilla aparte y no "subir vacio": dejar el
        // campo de archivo en blanco significa conservar el que hay, que es lo
        // que uno espera al venir solo a cambiar el color.
        if ($request->boolean('quitar_logo') && $configuracion->logo !== '') {
            Storage::disk('local')->delete($configuracion->logo);
            $configuracion->logo = '';
        }

        if ($request->hasFile('logo')) {
            // El logo es lo unico de esta pantalla que sale a internet en cada
            // pagina, asi que pasa por la misma conversion que las fotos.
            $ruta = 'institucion/logo-'.uniqid().'.webp';
            Storage::disk('local')->put($ruta, Imagen::aWebp($request->file('logo'), 320));

            if ($configuracion->logo !== '') {
                Storage::disk('local')->delete($configuracion->logo);
            }

            $configuracion->logo = $ruta;
        }

        // La firma, con la misma pareja de casilla-y-archivo que el logo. Se
        // guarda en PNG y no en WebP como todo lo demas: el generador de PDF no
        // entiende WebP, y una firma en WebP saldria como un hueco en el
        // certificado sin que nada fallara en pantalla.
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

        // Los dos formatos de autorizacion, con la misma pareja de
        // casilla-y-archivo que el logo y la firma, y por la misma razon: dejar
        // el campo de archivo en blanco significa conservar el que hay, que es
        // lo que uno espera al venir solo a cambiar otra cosa de esta pantalla.
        //
        // VAN POR SEPARADO A PROPOSITO. Se puede subir la del menor y dejar que
        // el sistema imprima la del mayor: no son el mismo papel con otro
        // titulo —un menor no otorga esta autorizacion por si mismo— y atarlas
        // obligaria a tener los dos antes de poder usar ninguno.
        foreach (['mayor', 'menor'] as $version) {
            $this->guardarFormato($request, $configuracion, $version);
        }

        $this->guardarCorreo($request, $datos, $configuracion);

        $configuracion->nombre_institucion = $datos['nombre_institucion'];
        // Los dos textos del firmante se guardan recortados y admiten quedarse
        // vacios: una institucion puede tener la firma escaneada antes de haber
        // decidido como se escribe el cargo.
        $configuracion->firmante_nombre = trim($datos['firmante_nombre'] ?? '');
        $configuracion->firmante_cargo = trim($datos['firmante_cargo'] ?? '');
        $configuracion->entidad_nit = trim($datos['entidad_nit'] ?? '');
        $configuracion->entidad_direccion = trim($datos['entidad_direccion'] ?? '');
        $configuracion->entidad_correo = trim($datos['entidad_correo'] ?? '');
        $configuracion->entidad_telefono = trim($datos['entidad_telefono'] ?? '');
        // VACIA SE GUARDA COMO NULL, y esa distincion es la funcion entera del
        // campo: null significa «publica el texto de fabrica», que se escribe
        // solo con el nombre y el contacto de esta entidad y se pone al dia
        // cuando cambian. Guardando '' se publicaria una politica en blanco, y
        // ademas no habria forma de volver atras desde la pantalla.
        //
        // El `?: null` no sobra aunque `ConvertEmptyStringsToNull` ya lo haga:
        // aqui se recorta antes, asi que un textarea con solo espacios o saltos
        // de linea —que ese middleware deja pasar— tambien vuelve al de fabrica.
        $configuracion->politica_datos = trim($datos['politica_datos'] ?? '') ?: null;
        // Estas dos guardan '' y no null: la columna no admite nulo y su vacio
        // significa lo mismo —«usa la de fabrica»—, que resuelve el modelo.
        $configuracion->finalidad_datos = trim($datos['finalidad_datos'] ?? '');
        $configuracion->finalidad_imagen = trim($datos['finalidad_imagen'] ?? '');
        $configuracion->color_acento = strtolower($datos['color_acento']);
        $configuracion->limite_promotorias_por_periodo = $datos['limite_promotorias_por_periodo'];
        $configuracion->promotorias_visibles_para_estudiantes = $request->boolean('promotorias_visibles_para_estudiantes');
        $configuracion->alerta_clase_no_dictada = $request->boolean('alerta_clase_no_dictada');
        $configuracion->alerta_abandono = $request->boolean('alerta_abandono');
        $configuracion->recordar_encuesta = $request->boolean('recordar_encuesta');
        $configuracion->correo_obligatorio = $request->boolean('correo_obligatorio');
        $configuracion->faltas_para_abandono = (int) $request->input('faltas_para_abandono');
        // Vacia se guarda como NULL: es lo que significa «desde el inicio del
        // periodo». Quien lo consigue de verdad es el middleware
        // `ConvertEmptyStringsToNull` de Laravel; el `?: null` es el cinturon
        // para el dia que ese middleware se quite, y no es adorno — con una
        // cadena vacia, MariaDB rechaza el INSERT con «Incorrect date value» y
        // la pantalla contesta un 500.
        $configuracion->alertas_desde = $request->input('alertas_desde') ?: null;
        $configuracion->save();

        $respuesta = redirect()->route('gestion-configuracion')
            ->with('success', 'Configuración de la institución actualizada.');

        // El contraste no bloquea: una marca clara puede ser legitima, pero el
        // texto blanco de los botones deja de leerse y hay que avisarlo.
        $razon = $configuracion->contraste_texto_boton;

        if ($razon < 4.5) {
            $respuesta->with(
                'error',
                'Ojo: el texto blanco sobre ese color de acento queda en '
                .number_format($razon, 1).':1 de contraste, por debajo del mínimo de 4.5:1. '
                .'Los botones serán difíciles de leer; considera un tono más oscuro.'
            );
        }

        // La prueba de envio va DESPUES de guardar y en la misma peticion, no
        // en un boton aparte. Es a proposito: separadas, se prueba lo que hay
        // guardado y no lo que se acaba de escribir, y el orden —guardar
        // primero, probar despues— hay que acordarselo. Aqui no hay orden que
        // recordar, y lo que se prueba es siempre lo que se acaba de guardar.
        if ($request->filled('correo_prueba')) {
            $this->probarElCorreo($request->string('correo_prueba')->toString(), $respuesta);
        }

        return $respuesta;
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
