# ADYP · Fase 2 — Tres conceptos visuales premium (Mobile-First)

Los tres conceptos comparten la misma arquitectura funcional (paridad 100% con rutas/validaciones del repo) y se aplican en el prototipo como *design tokens* conmutables (selector **A/B/C** en cualquier ruta). Fuentes del sistema del prototipo: `"Fraunces"→Georgia/serif`, `"Plus Jakarta Sans"→system-ui`, `"Outfit"→numeric fallback` (zero-CDN: sin `@font-face` remoto; en producción se instalan los woff2 locales ya incluidos en `/fonts`).

---

## Concepto A · «Clean Minimalist & Glassmorphism Fluid»
**Idea:** aire, luz y jerarquía tipográfica. Superficies translúcidas (blur 18–26px) sobre un fondo con dos lavados radiales (turquesa + ámbar al 6–9%). La tinta manda; el color solo señala. Menos bordes, más separación; la elevación es sutil y difusa.

| Token | Valor |
|---|---|
| Colores | `--bg:#f6f8f9` · `--papel:rgba(255,255,255,.72)` · `--tinta:#101714` · `--pino:#14523f` (acciones) · `--agua:#0e7f95` (info/mapa) · `--sol:#e8a404` (acentos AA) · `--ok:#1c7a3f` · `--peligro:#b23a2c` · semánticos sobre tinte 10–14% |
| Tipografía | Escala fluida clamp: 12→13 (micro) / 15 (body) / 17 / 20 / 26 / 34 / 44 (display Fraunces 600 `opsz`). Interlineado 1.55. Letter-spacing +.01em en body, –.02em en display |
| Espaciado | Grid 8px: 4/8/12/16/24/32/48 (igual que producción; `--sp-*`) |
| Radio | 12 (chips) / 18 (inputs) / 24 (cards) / 32 (sheets) / full (pills) |
| Elevación | `0 1px 2px rgba(16,23,20,.05), 0 12px 32px -18px rgba(16,23,20,.22)`; hover = translateY(-2px)+blur ring |
| Movimiento | `cubic-bezier(.22,.8,.22,1)` 220–320ms; hojas con spring suave; *breathing* solo en FAB activo |
| Densidad | Baja (mucho aire, 6–8 tarjetas/ pantalla móvil) |
| Contraste | AA+ en todo texto; focus ring doble (2px tinta + 4px halo ámbar) |

**Componentes sello:** tarjeta-quedada "vidrio" con barra de aforo de 3px en la base del cover; chips de filtro cápsula con check animado; inputs flotantes (label sube a 11px + relleno superior); bottom-sheet con handle 36px y detents 60/90dvh; toasts vidrio con icono morfológico; navbar inferior flotante (márgenes 10px, radio 28, blur 24) en tablet se vuelve dock vertical izquierdo.

## Concepto B · «Vibrant Premium Community»
**Idea:** energía de peña: tarjetas blancas elevadas sobre lienzo crema saturado, acentos pino+ámbar a plena potencia, bordes 2px y radios generosos, badges expresivos (aforo lleno = coral con pulso, quedan ≤5 = ámbar, abierto = pino). Tipografía Outfit para números gigantes (datos vivos), Jakarta bold para UI.

| Token | Valor |
|---|---|
| Colores | `--bg:#fbf3e4` · `--papel:#ffffff` · `--tinta:#1d1505` · `--pino:#0f6a4c` · `--agua:#0aa2c0` · `--sol:#f59e0b` · `--coral:#e05b4b` · `--exito:#1f8a68` · anillos de 2px en `rgba(15,106,76,.14)` |
| Tipografía | Display Outfit 800 (números/títulos, tracking –.03em), body Jakarta 16.5; escala 12/15/18/22/28/38/52; peso UI 700 |
| Espaciado | 8px con múltiplo base 16; tarjetas `gap:12`, secciones `24/40` |
| Radio | 16 / 20 / 28 / 36 (sheets) — nunca <16 en superficies |
| Elevación | Duradera: `0 2px 0 rgba(29,21,5,.06), 0 18px 34px -20px rgba(29,21,5,.35)` + borde 2px `#f0e6d2`; pressed = translateY(2px) |
| Movimiento | `cubic-bezier(.34,1.56,.64,1)` (salto elástico) en botones primarios y badges; aparición de tarjetas con stagger 40ms |
| Densidad | Media (7–10 tarjetas; ficha con hero + meta en píldoras) |
| Contraste | AA estricto (ámbar oscurecido para texto); blanco sobre pino 5.8:1 |

**Componentes sello:** badge de aforo con mini-barra segmentada (●●●●○○) y contador Outfit; chips de filtro rellenos que se "hunden" al tocar (active 96%); inputs con borde 2px que se vuelve pino + sombra de foco; barra de acción inferior pegada (CTA full-width 52px) en la ficha; tabbar adosada con indicador "píldora deslizante"; toasts con borde izquierdo 4px de color; avatares de socios con anillo por rol (admin sol, mod pino).

## Concepto C · «Modern Warmth & Neo-Editorial»
**Idea:** la herencia crema/papel de ADYP como revista: Fraunces en display con *soft/wonk*, filetes finos como separadores, tipografía como sistema de navegación; el color es una mancha de tinta (pino) y una cera (ámbar) usadas como rotulador — subrayados, resaltados, sellos. Microinteracciones de alta fidelidad (subrayado que se despliega, sello que gira 3° al confirmar).

| Token | Valor |
|---|---|
| Colores | `--bg:#faf6ec` · `--papel:#fffdf7` · `--tinta:#221a10` · `--pino:#14523f` · `--agua:#0f7188` · `--sol:#c98a12` (tinta-ámbar AA) · `--linea:#e3d8c6` (filetes) · avisos en `#fdf1e3` |
| Tipografía | Fraunces 500–650 `SOFT 0 / WONK 1` display; Jakarta para cuerpo 17/1.62; eyebrows en Outfit 11px 700 +.14em versalitas; escala 12/15.5/19/24/32/42/56 con *drop caps* en fichas |
| Espaciado | 8px, ritmo editorial: secciones 32/56, líneas de 1px entre bloques (sin sombras) |
| Radio | Conservador: 10 (chips) / 14 (inputs) / 18 (cards) / 30 (sheets); fotos con 6px |
| Elevación | Mínima: `0 1px 0 var(--linea)` + hover `0 10px 24px -18px rgba(36,21,5,.3)`; profundidad por *layering* de papel (bordes escalonados), no por sombra |
| Movimiento | 280–360ms `cubic-bezier(.16,1,.3,1)`, reveal de línea (mask-reveal), subrayado animado en enlaces, sin rebotes |
| Densidad | Alta-typográfica (más texto por cm², listas densas tipo sumario) |
| Contraste | Máximo AA (tinta #221a10 sobre #faf6ec = 12.9:1); focos en pino con subrayado doble |

**Componentes sello:** agenda como *sumario* (día en Fraunces + línea de puntos + entradas), tarjetas-quedada con "folio" editorial (nº + fecha en vertical), badge de aforo tipo sello de tinta, filtros como *marginalia* (chips subrayados con tachado al quitar), inputs sin caja con filete inferior que "sangra" al enfocar, sheet con cabecera "índice" y handle, toasts que entran como nota adhesiva, tabbar con serif en el item activo.

---

## Tabla comparativa

| Criterio | A · Glass Fluid | B · Vibrant Community | C · Neo-Editorial |
|---|---|---|---|
| Personalidad | Sereno, premium, tecnológico | Lúdido, cálido, colectivo | Culto, cálido, artesanal |
| Contraste texto | AA+ (tinta sobre vidrio; vigilar blur sobre foto) | AA estricto, sube peso a 700 | Máximo (12:1), tipografía manda |
| Velocidad percibida | Alta (pocas líneas, blur barato) | Muy alta (números grandes, escaneo rápido) | Media (más lectura, más contexto) |
| Densidad de info | Baja-media | Media | Alta tipográfica |
| Esfuerzo dev | Medio (backdrop-filter en iOS/Android a test) | Bajo-medio (estados y sombras) | Medio (variables de fuente óptica) |
| Riesgos | Legibilidad del vidrio sobre imágenes; rendimiento blur | Fatiga visual si todo "grita"; sombras pesadas en lista larga | Serios en móvil si el estilo editorial aplasta la UI táctil |
| Para quién brilla | Usuarios que quieren calma y foco | El núcleo social de ADYP (peñas, peques, eventos llenos) | La identidad de marca actual (crema+Fraunces) evolucionada |
| Recommendation | Excelente por defecto en modo oscuro | **Mejor por defecto móvil** (afinidad con la app real) | Base de marca a futuro |

**Nota de paridad:** los tres conceptos se limitan a la capa de presentación y microinteracción. No alteran rutas (`/,/entrada,/unirme,/agenda,/mapa,/quedada/:id,/quedada/nueva,/mis,/comunidad,/perfil,/moderacion,/admin/*`), validaciones (70/600/20–4000/1–400/999/≥3 participadas/montaña km+desnivel+vuelta≠salida…), reglas de aforo (deriva de inscripciones, espera con turno + 15 min, sin sobreventa) ni permisos (socio/moderador/admin, último admin, export solo admin). La capa móvil v2 (sheets con detents y allowlist) se conserva y amplía; la v1 no se recupera en ningún concepto.
