# ADYP · Prototipo de diseño premium (Fase 3)

Archivo único autónomo: **`index.html`** — zero-CDN, 100% inline (HTML+CSS+JS), sin dependencias.
Ábrelo con doble clic o sírvelo estáticamente (`python3 -m http.server`).

## Contenido
- **Router SPA vanilla** con todas las rutas reales de ADYP: `/`, `/entrada`, `/unirme`, `/agenda`, `/mapa`, `/quedada/:id`, `/quedada/nueva`, `/mis`, `/comunidad`, `/perfil`, `/moderacion`, `/admin/{resumen,altas,socios,quedadas,auditoria,ajustes,datos}` y `/prompt`.
- **Selector A/B/C** flotante (esquina derecha): 3 conceptos premium conmutables en cualquier ruta (tokens en `html[data-concept]`).
- **Simulador DEMO** (botón en la cabecera): rol socio/moderador/admin, estados Carga (skeletons) / Vacío (empty) / Error (retry), cambio de clave obligatorio, reset.
- **Movilidad**: tabbar 5 huecos con FAB, bottom-sheets con arrastre y dos detents (allowlist equivalente a la capa v2: filtros, formulario, normas, confirmación de plaza; las confirmaciones genéricas siguen siendo diálogo centrado), `safe-area-inset-*`, objetivos táctiles ≥44-48px, `:focus-visible`, `prefers-reduced-motion`.
- **Validaciones idénticas a producción**: 70/600/20–4000, 1–400 plazas (999=abierto), montaña km+desnivel+dificultad, vuelta≠salida, ≥3 participadas, teléfono E.164-style, contras 4–72, confirmación literal ELIMINAR, aforo ≥ inscritos, última-admin, etc.
- **Mapa esquemático SVG local** con pins/clusters/zoom/pan — *no* Google Maps (declarado en la UI).
- **Módulo «Prompt completo»** (`#/prompt`) con el prompt íntegro y botón «Copiar prompt» (Clipboard API + fallback).

## Transparencia
Sin backend PHP ni BD: login, inscripciones/cola con turno, moderación, auditoría, copias y exportación están **simulados en memoria/localStorage** con los textos y reglas extraídos del bundle de producción (`assets/index-*.js`) y de `api/*.php` (comentarios). No se usa ninguna foto ni dato real de socios. Fuentes: se usan Fraunces / Plus Jakarta Sans / Outfit si están instaladas en el sistema; si no, fallback tipográfico (no hay CDN por requisito).

## Documentos de la auditoría
- `../adyp-diseno-premium/auditoria.md` — Fase 1 (inventario de rutas, flujos, permisos, diagnóstico responsive/WCAG).
- `../adyp-diseno-premium/conceptos.md` — Fase 2 (3 conceptos, tokens y tabla comparativa).
