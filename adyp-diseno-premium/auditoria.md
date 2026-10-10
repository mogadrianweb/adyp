# ADYP · Fase 1 — Auditoría heurística y de arquitectura

**Fuentes auditadas:**
- Producción: `https://adisfrutarypunto.alwaysdata.net/` (redirige `/` → `/entrada`; comunidad privada, sin contenido indexable sin sesión).
- Copia de referencia: `https://adisfrutarypunto.alwaysdata.net/testdesign/index.html` (mismo shell SPA; el contenido vive en el bundle compilado).
- Repositorio `mogadrianweb/adyp`: build compilado de React (`assets/index-BbhyA3V1.js` + `index-Cl536Zhv.css`), capa móvil **v2** (`adyp-mobile-ui-c26aa4e4bd56.css` + `adyp-mobile-ui-68a2e19014d8.js`, la referenciada en `index.html`; la v1 está en el repo pero **no activa** — respetada como restricción), API PHP comentada (`api/auth.php`, `api/eventos.php`, `api/foto.php`) y PWA (`manifest.webmanifest`, `_headers`, `_redirects`, CSP).

> Todo lo marcado ✅ está verificado en el código/HTML entregado. Lo marcado ⚠️ es regla del backend PHP no ejecutable aquí. Lo marcado ❓ es suposición razonable sin confirmación visual (falta sesión real/BD).

---

## 1. Inventario completo de rutas

### Públicas / socios
| Ruta | Pantalla | Guard | Observado |
|---|---|---|---|
| `/` | **Inicio** personalizada | sesión | ✅ "¿Te apetece salir?", "Tu próxima historia", "Plan(es) para ti", "Esta semana" con chips rápidos (Cerca de mí / Con niños / Al aire libre / Filtrar), "Tu racha", "Asistencia", "Accesos del equipo" (solo rol equipo), CTA "Proponer una quedada… lleva menos de tres minutos" |
| `/entrada` | **Login** | anónimo (logueado → `/`) | ✅ Título "Sumando momentos", teléfono con regla de normalización ("600 00 00 18 = 600000018 = +34600000018"), contraseña, "Acceder", "¿No la recuerdas?", "¿Quieres pertenecer a la comunidad?", aviso "Comunidad cerrada", tarjeta PWA (APK Android v1.2 + instrucciones iPhone) y enlace WhatsApp con placeholder `REEMPLAZAR-POR-ENLACE-CADUCO`. Forzador de cambio de contraseña provisional (`obligar_clave`, mostrado una vez) ⚠️ |
| `/unirme` | **Solicitud de alta** (wizard) | anónimo | ✅ "Aquí empieza tu solicitud…". Campos: nombre, apellidos (≤90), teléfono (obligatorio), email, fecha de nacimiento (→ tramo 18-30 / 30-55 "Al poner tu fecha te decimos en qué tramo encajas"), municipio (≤60), presentación (≤600), casilla "Acepto las normas de la comunidad (versión 10/2026)". Estados ⚠️: `pendiente` → aprobado (contraseña provisional de 6 dígitos que solo ve el admin para pasarla por WhatsApp) / rechazado con motivo. Antiduplicado por teléfono normalizado |
| `/agenda` | **Agenda** | sesión | ✅ Búsqueda ("Buscar «Maigmó», «cena», «Elche»…"), filtros: categoría, disponibilidad ("Solo con hueco"), público (peques), zona/ciudad; franja de semana con "Semana anterior/siguiente", calendario por meses ("Abrir el calendario por meses", punto en días con quedadas, "Ir a hoy", "Quitar el día"); chips activos removibles ("Quitar el filtro de…"); empty "Nada por aquí… todavía 👀"; CTA "Proponer una quedada" |
| `/mapa` | **Mapa de quedadas** | sesión | ✅ Google Maps real con clave restringida ⚠️; **fallback propio "mapa esquemático sobre coordenadas reales · no es cartografía"**; zoom Acercar/Alejar, Encuadre general, pins por evento con clusters ("Quedadas en este punto"), tarjeta lateral (imagen, plazas, "Cómo llegar", "Ver la quedada"), teclas ←/→ y Esc; tabs "Todas las quedadas / Solo próximas / Próximas y pasadas"; comarcas: Alacantí, Aitana, Alcoià·Comtat, Vinalopó, Marina Alta, Cabo de las Huertas… |
| `/quedada/:id` | **Ficha de quedada** | sesión (ficha pública compartible `es_publica`) | ✅ Ver detalle abajo (flujos). Badges: "Quedan N" / "Sin plazas libres" / "Reservar mi plaza · aforo abierto" / "Sin aforo declarado" ("No declara aforo: se podrá inscribir gente sin control"). Lista de espera con turno ("Entras con turno N", "Si alguien libera plaza, entra la tuya"), ventana "¡Plaza libre! 15 min", cancelación ("Has cancelado. La plaza vuelve a la agenda"), confirmación "Confirmar plaza" (modal que la capa v2 convierte en sheet), enlace grupo WhatsApp ("caduca a los 7 días"; "El organizador todavía no ha puesto enlace de grupo"), coche compartido ("Nadie ha ofrecido coche todavía", "Necesito plaza en coche", "Yo pongo coche y quiero organizar plazas", "Plazas libres en tu coche"), peques/acompañantes, "Material recomendado", "De qué va", "Dónde nos vemos", "Dinero: Gratis/…", "Del organizador: …", panel del organizador, estados bloqueados ("Bloqueada por el equipo", "Las inscripciones de esta quedada están cerradas por el equipo", "Esta quedada todavía no está aprobada", "Esta quedada ya ha pasado", race-condition: "Alguien se ha apuntado en este segundo. Recargamos el aforo: vuelve a intentarlo") |
| `/quedada/nueva` | **Proponer quedada** | sesión + **≥3 quedadas participadas** ("Para proponer una quedada debes haber participado en al menos 3 quedadas de la comunidad." → "Todavía no puedes proponer quedadas") | ✅ Ver detalle abajo (validaciones). Envío → "Quedada enviada a moderación. Te avisamos aquí mismo, no solo por WhatsApp." |
| `/mis` | **Mis quedadas** | sesión | ✅ Tabs "Organizo / Apuntado / En espera"; empties: "No tienes ninguna plaza reservada ahora mismo", "No estás en ninguna lista de espera", "Todavía no organizas nada", "Recordatorio del sistema" (avisos) |
| `/comunidad` | **La comunidad** | sesión | ✅ Fichas de socios "Disfruteros / Disfrutines / Disfrutones", vistas "Por afición / Por zona", "Normas de la casa" (modal centrado `.normas` → sheet v2), edición de "Enlace de WhatsApp del grupo" (equipo) con estados Guardando…/error |
| `/perfil` | **Mi Cuenta y reputación** | sesión | ✅ Ver detalle abajo (perfil) |
| `/moderacion` | **Cola de moderación** | equipo (mod/admin) | ✅ Ver detalle abajo (moderación) |

### Administración `/admin/*` (componente `AdminLayout`)
Subrutas verificadas en el bundle: `altas`, `socios`, `quedadas`, `auditoria`, `ajustes`, `datos` y `*` (→ resumen). Guard: "Este panel es para la organización" / `rol==="admin"` para acciones duras; "Esa sección del panel no existe".

| Sección | Contenido verificado |
|---|---|
| **Resumen** | KPIs: absentismo ("Absentismo registrado", % con alerta `>15`), "Ciclo de vida", "De dónde sale cada cifra", "Nada para hoy. Es un buen día para revisar las altas.", "Todo en orden", atajo "Ir al resumen"/"Saltar al contenido" (skip-link) |
| **Altas** | Bandeja de solicitudes (`estadoFiltro`), aprobar (→ contraseña provisional visible una vez, "Contraseña copiada al portapapeles."), "Motivo del rechazo", rechazar; duplicados por teléfono |
| **Socios** | Tabla buscable (nombre/apellidos/teléfono/correo/municipio/tramo/"Miembro desde"), rol (promover/admin/moderador con **regla del último admin**: `rol==="admin"&&…` impide quitar el último), "Suspender"/"Reactivar"/"Bloqueado desde el panel", "Reiniciar contraseña", contadores `participadas_manual/entradas_manual/ausencias_manual` ("Ajustar reglas"), foto (44/64px, "Quitar foto"), estado pendiente/activo/rechazado/suspendido |
| **Quedadas** | "Gestionar quedadas": estado Pendiente/Aprobada/Cancelada, "Ajustar aforo" con regla dura `El aforo no puede bajar de N` (inscritos), "Cerrar inscripciones", "Cancelar la quedada", "Eliminar esta quedada" ("Esto no se puede deshacer", confirmación literal), "Duplicar", "Foto de portada" |
| **Auditoría** | Registro `auditoria(usuario_id, accion, detalle)` ⚠️; búsqueda "Buscar en el detalle"; "No se ha podido leer la base de datos", "Reintentar" |
| **Ajustes** | "Ajustes globales": "Exigir aforo declarado en toda quedada", "Antelación mínima para publicar una quedada", "Acompañantes máx. por socio", "Aviso previo", "Bienvenida para nuevos socios", expiración de invitación WhatsApp ("La invitación al grupo de WhatsApp caduca a los…") |
| **Datos** | "Datos y copias": "Checklist antes de exportar datos reales", "Descargar CSV", "Preparar exporte", permiso duro: "Tu rol no puede exportar datos. Es una decisión de diseño, no un fallo.", "Registrar copia ahora"/"Copia hecha"/"Copia real registrada en el servidor (fuera de la carpeta pública)", "Nueva fecha para la copia", "Anonimizar la ficha", `TRUNCATE`/`DELETE` con "Confirmación literal" ("Antes de tocar nada", "Qué hace esto de verdad", "DELETE") |

---

## 2. Mapeo de flujos, formularios y permisos

### 2.1 Formulario «Proponer quedada» (`/quedada/nueva`) — validaciones ✅
- **Título**: obligatorio, **máx 70** ("Máximo 70 caracteres: si no cabe en la tarjeta, no lo lee nadie").
- **Fecha**: obligatoria ("Falta el día."), no pasada ("La fecha ya pasó").
- **Hora de salida** obligatoria; **Vuelta prevista** obligatoria ("Hora de vuelta prevista: quien espera a un hijo en casa lo agradece") y ≠ hora de salida ("La vuelta no puede ser a la misma hora que la salida").
- **Categoría → Subcategoría** dependientes ("Elige una categoría y aparecerán sus subcategorías."; "La subcategoría no es de esa categoría.").
- **Punto de encuentro** + nombre del lugar; coordenadas opcionales (alimentan `/mapa`).
- **Plazas/aforo**: obligatorio (1–400; "El aforo va de 1 a 400 plazas."; ilimitado = escribir **999** → UI muestra "aforo abierto"; con ajuste global "Exigir aforo declarado" ⚠️).
- **Montaña** (cat. 5): exige **km, desnivel y dificultad 1–5** ("En montaña hacen falta km, desnivel y dificultad.", "Falta nivel de dificultad o desnivel en montaña", "Las fichas de montaña se leen enteras antes de apuntarse").
- **Descripción**: 20–4000 caracteres. **Presentación** ≤600.
- **Material obligatorio** (uno por línea), "Frontal obligatorio" como ejemplo.
- **Peques**: aptas / edades ("Aptas para peques", "Permite peques sin edad mínima", tramos por subcategoría `i.min!=null&&i.max!=null? admisible ${min}–${max}`).
- **Dinero**: precio + nota. **Enlace WhatsApp** opcional al crear ("Enlace del grupo de WhatsApp (opcional ahora)"; advertencia "Sin enlace de WhatsApp: en cuanto se llene no hay cómo avisar"; "Se están cogiendo las quedadas de la base de datos" — portada: "Sin foto propia: se usa la de la categoría", subida ≤ **6 MB** con aviso "Máximo 6 MB…").
- Coche: "Yo pongo coche y quiero organizar plazas" + "Plazas libres en tu coche".
- Error de envío: "No se pudo enviar. Repasa los datos marcados."

### 2.2 Flujo de plaza (`/quedada/:id`) ⚠️/✅
inscribir → **atómico FOR UPDATE** (sin sobreventa); lleno → `en_espera` con turno; cancelar_inscripcion → sube el primero; ventana "¡Plaza libre! 15 min" desde cola; versión de aforo (`versionEsperada`) para resolver races; check-in con **QR que caduca a las 12 h y solo funciona una vez** (`checkin`); asistencia presente/ausente/borrar → alimenta absentismo.

### 2.3 Moderación (`/moderacion`) — acciones de equipo
Cola `Pendiente`; el sistema detecta y lista avisos (descripción corta, sin vuelta, montaña sin desnivel, sin aforo, enlace WhatsApp de otra actividad, requiere nivel físico sin aviso). Acciones: **aprobar** / **devolver para corregir con motivo** ("Devuelta para corregir. No cuenta como rechazo.") / **rechazar con motivo** ("Rechazada con motivo. El autor ve el motivo exacto y puede corregir y reenviar.") / **bloquear** ("Bloqueada. Queda registrada en auditoría con tu nombre."). "Ficha abierta en una pestaña nueva (así no pierdes la cola)". Validador QR: "Código del QR (o pulsa un nombre)" → "Todo el mundo dentro." / "QR no válido para esta quedada".

### 2.4 Perfil (`/perfil`)
Datos editables (población, zona "Elige tu zona: las quedadas se organizan por comarca", presentación corta, categorías de interés); foto (validación tipo "Ese archivo no es una imagen…" + tamaño); cambio de contraseña en pantalla ("Con tu contraseña actual…", mín 4, máx 72, coincidencia; "Contraseña cambiada…"; "Cerradas todas las sesiones menos esta."; "Cerrar resto"); **2FA** ("La 2FA se activa en el siguiente paso del asistente.", "Guardar permisos"); notificación por email al liberarse plaza de espera ("Esto sustituye a «entrar cada dos días a mirar la lista entera»"); reputación (Asistencias/participadas); "Descargar mis datos" (RGPD); "Cerrar sesión".

### 2.5 Permisos por rol (frontend ✅ / backend ⚠️)
- **socio/usuario**: solo sus fluxos + proponer (si ≥3 participadas) + compartir ficha pública.
- **moderador** (`esEquipo`): moderación, gestión de quedadas, comunidad (enlace WhatsApp), check-in QR; **sin** datos masivos ("Tu rol no puede exportar datos…") ni gestión de roles.
- **administrador**: todo + altas/socios/roles/ajustes/datos/auditoría; no puede dejar sin admin a la comunidad (regla del último admin).

### 2.6 Separación frontend confirmado vs. suposiciones de API
Confirmado en UI/bundle: textos, validaciones, estados, guards, sheet-v2, tabbar, tokens, theme `data-tema=oscuro`, CSP/PWA.
**No verificable sin backend (⚠️)**: envio real SMS/códigos, emails de "plaza libre", expiración 7d de enlace WhatsApp, generación de contraseñas provisionales, cron de copias, auditoría persistida, latencia de carga real, Google Maps (clave en `.env`, restringida por dominio) y normalización de teléfono E.164 compartida con `lib/db.js`. En el prototipo todo esto se **simula** y se declara en la propia UI.

---

## 3. Diagnóstico responsive y de accesibilidad (WCAG 2.1 AA)

### Lo que ya está bien ✅
- **Mobile-first real**: tabbar fija de 5 huecos con FAB central (Mapa), `--barra: 56px` (62/78px en otros breakpoints), `padding-bottom: env(safe-area-inset-bottom)`, `viewport-fit=cover`; laterales usan `safe-area-inset-left/right`.
- **Capa móvil v2** (activa): bottom-sheets con **dos detents** (peek/expanded) vía handle táctil (320px grabables), `max-height` con `dvh` + `safe-area-inset-top`; allowlist deliberada: filtros, formulario de propuesta, normas y "Confirmar plaza"; confirmaciones genéricas y alertas de acceso se quedan como diálogo centrado (decisión documentada en el código).
- `:focus-visible` global (outline 3px ámbar + offset), skip-link "Saltar al contenido", `prefers-reduced-motion` que apaga las animaciones decorativas, `aria-current=page` en tabbar, `aria-label` en diálogos, `role=status`/live regions para avisos, `lang="es"`.
- Inputs base `min-height:48px` en móvil; chips removibles con botón propio; empty/error/skeleton presentes (`.esqueleto`, `.toasts`).
- CSP estricta, `noindex`, sin claves en HTML (la única es la de Maps, documentada).

### Fricciones detectadas ⚠️ (a resolver en el rediseño)
1. **Objetivos táctiles <48px**: `.btn{min-height:46px}`, en <1040px `.btn{min-height:40px}`, `.btn.mini{min-height:34px}`, `.btn-icono{width:42px}`, `.select{min-height:44px}` — por debajo de la norma 48×48 pedida (y del propio 2.5.5 AAA; AA 1.4.11 exige 24px pero tátil cómodo exige 44+).
2. **Etiquetas de tabbar a 10.56px** (`.66rem`) — legibilidad límite en exterior/sol; aumentar a ≥11px con peso 700.
3. **Contraste en tintes suaves**: `--tinta-3:#7a6650` sobre `--arena-20:#efe8d8` ≈ 4.0:1 (fracasa AA para texto normal); `--sol:#f5b301` como fondo con `--tinta` es correcto, pero el foco ámbar sobre crema ≈ 3.2:1 (vale para foco no-texto AA 1.4.11 solo con halo — el halo actual mitiga, añadir borde doble en el rediseño).
4. **Zona de pulgar**: CTA primario "Proponer" vive en el header/tabbar pero la acción de plaza está a mitad de pantalla en fichas largas → fijar barra de acción inferior en ficha (además del FAB).
5. **Desbordes**: tabla de admin y `dl` de resumen no declaran scroll-x contenedor → en 360px el `Auditoría` (detalle JSON) desborda; requerir contenedores `.tabla-movil` con swipe.
6. **Mapa**: el fallback esquemático cubre el pinch-to-zoom (gesto único = arrastre de mapa) con la UI de "Acercar/Alejar" — mantener pero aumentar hit-areas de pins a 44px.
7. **Modal "Confirmar plaza"**: en v2 es sheet 'detail' con peek 68dvh — al ampliar, el botón primario queda bajo el teclado virtual en campos con input; reservar `env(safe-area-inset-bottom)` + scroll-into-view al enfocar.
8. **`orientation:"portrait"` en el manifest** bloquea el uso horizontal en tablet (aquí se pide tablet H/V) → pasar a `any` en el rediseño (documentado como cambio recomendado, no aplicado al repo).
9. **Sin previsualización ni badge de cantidad en tabbar** (mis-quedadas entra por menú); hueco para badge de avisos del sistema ("Avisos, nada pendiente").
10. **Estados de carga**: `.esqueleto` existe pero la agenda de inicio usa spinner de logo completo (splash) — los skeletons deberían cubrir tarjetas, no solo listas.

### Matriz de breakpoints observada
| Ancho | Comportamiento |
|---|---|
| <700px | 1 col, tabbar, sheets con handle (v2), inputs 48px, btns 40px ⚠️ |
| 700–1039px | 2 cols, paneles laterales, modales centrados `max-w` |
| ≥1040px | `--ancho:1180px`, nav de escritorio (`nav-escritorio`), admin con sidebar, tabbar oculta |

---

## 4. Conclusiones para el rediseño (Fase 2/3)
- Conservar: arquitectura de rutas, reglas de negocio, textos de validación (son oro de UX), capa v2 de sheets (ampliándola), tokens de marca (pino/turquesa/ámbar/crema + Fraunces/Jakarta/Outfit).
- Mejorar: tamaño táctil global ≥48px, barra de acción fija en ficha, badges de aforo más semánticos (cuántos quedan + progress), contrastes AA en tintes, skeletons por tarjeta, swipe-x en tablas admin, tablet horizontal con paneles dobles, y transiciones de ruta fluidas sin dañar `prefers-reduced-motion`.
- Todo estado no verificable por backend se simula en el prototipo y se declara en su UI (banner «simulación» + sección de transparencia).
