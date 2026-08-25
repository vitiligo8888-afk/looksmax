# Guías retiradas por ser demasiado básicas

Criterio (del usuario, 2026-08-25): *"entiendo si dices mejores ejercicios etc
pero no CÓMO hacer un ejercicio básico que lleva mucha historia"*.

Enseñar a ejecutar la sentadilla, el peso muerto o el press de banca no aporta
nada: son movimientos documentados hasta el cansancio en todas partes. Lo que sí
se queda es la selección y la programación — "los cuatro patrones base", "formas
de progresar", "por qué no te saltes las piernas" — porque ahí sí hay criterio.

## Qué se retiró

| discussion_id | título |
|---|---|
| 67942 | Peso muerto: técnica y seguridad |
| 67949 | Press de banca: técnica |
| 67965 | Sentadilla: técnica correcta |

## Cómo devolverlas

Están ocultas, no borradas, con un centinela de fecha exclusivo de este lote
(ningún otro de los 110 lotes ocultos lo usa):

```sql
UPDATE discussions
   SET hidden_at = NULL, hidden_user_id = NULL
 WHERE hidden_at = '1997-03-14 00:00:00';
```

Y devolver los .md de esta carpeta a `content/original/`.
