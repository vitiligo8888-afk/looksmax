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

---

## Segundo lote: "retira lo super básico" (2026-08-25)

Mismo criterio, ya no solo ejercicios. La prueba: **¿alguien que entra a un
foro de looksmaxing ya lo sabe sin que se lo digan?** Si sí, sobra.

Centinela: `1997-03-15 00:00:00` (13 guías).

- Higiene corporal: cómo ducharte bien
- Rutina de higiene diaria: checklist práctico
- Hidratación: cuánta agua necesitas
- Snacks sanos · Cómo comer fuera · Fibra y digestión · Meal prep básico
- Alimentación para tener más energía · Más energía en el día
  (estas dos se solapaban casi entera la una con la otra)
- Salud ocular y pantallas (regla 20-20-20)
- Chequeos médicos básicos para jóvenes — además era justo el contenido
  "ve al médico / señales que no debes ignorar" que el usuario prohibió
- Calentamiento · Estiramiento — básicos con mucha historia, mismo caso
  que la sentadilla

### Qué NO se retiró, y por qué

- **Higiene bucal y sonrisa** — lleva "blanqueamiento: seguro vs daño" y
  "alineación y forma". Eso es looksmaxing, no cepillarse los dientes.
- **Mal aliento** — explica que viene de la lengua y no de la boca en
  general; es un dato que la gente no trae.
- **Cuidado de la ropa** — "lavar menos y mejor" es contraintuitivo.
- **Rutina de mañana / de noche** — son de piel y grooming, específicas.

```sql
UPDATE discussions SET hidden_at=NULL, hidden_user_id=NULL
 WHERE hidden_at='1997-03-15 00:00:00';
```
