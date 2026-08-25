# Terceros

## spin-wheel

- Origen: https://github.com/CrazyTim/spin-wheel
- Fichero: `dist/spin-wheel-iife.js` de la rama `main`
- Licencia: MIT (`LICENSE-spin-wheel.txt`)
- Sin dependencias. Expone el global `spinWheel` con la clase `Wheel`.

Se guarda aquí en vez de cargarla de un CDN porque esta web sirve todo desde su
propio origen; y se sirve por `GET /api/economy/wheel/lib.js` en lugar de
concatenarse al bundle de Flarum, porque el bundle es compartido y un throw ahí
tumba todas las extensiones que carguen después (ver InjectCosmetics.php).

Actualizar = volver a bajar ese fichero y comprobar que `spinToItem()`,
`onRest` y `onCurrentIndexChange` siguen existiendo: es toda la API que se usa.
