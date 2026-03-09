# WATI WhatsApp Plugin para GLPI 10

Este plugin integra **GLPI 10** con la API de **WATI**, permitiendo una comunicación bidireccional inteligente entre técnicos y solicitantes directamente a través de WhatsApp.

## 🚀 Funcionalidades Estrella

### 1. Notificaciones Inteligentes (Push)

* **Asignaciones:** Los técnicos reciben un WhatsApp instantáneo cuando se les asigna un ticket.
* **Seguimientos (Doble Vía):** * Si el **Solicitante** comenta, el **Técnico** es notificado.
* Si el **Técnico** comenta, el **Solicitante** recibe la actualización.


* **Anti-Eco:** El sistema detecta al autor y evita enviarte notificaciones por tus propios mensajes.

### 2. Bot de Autoservicio (Webhooks)

El plugin incluye un `webhook.php` de alto rendimiento que permite:

* **Validación de Identidad:** Verifica si un número de WhatsApp pertenece a un usuario registrado en GLPI.
* **Registro de "Guests":** Si el usuario no existe, permite capturar nombre y correo desde WhatsApp para crearlo automáticamente en GLPI.
* **Consulta de Status:** El usuario puede listar sus tickets abiertos, ver su estado (Nuevo, En curso, etc.) y leer el último comentario del técnico.
* **Comentarios Remotos:** Los usuarios pueden responder a un ticket escribiendo en WhatsApp, lo cual se registra automáticamente como un seguimiento (`ITILFollowup`) en GLPI.

---

## 🛠️ Instalación y Configuración

1. **Directorio:** Coloca la carpeta en `plugins/watiplugin`.
2. **Activación:** Ve a **Configuración > Plugins** y activa "WATI Plugin".
3. **API:** Configura tu Endpoint y Token de WATI en la interfaz del plugin.
4. **Campos de Usuario:** El sistema utiliza los campos **Móvil** o **Teléfono** de la ficha del usuario para el envío.

---

## 🔗 Endpoints del Webhook (Para WATI Flow Builder)

El archivo `webhook.php` acepta las siguientes acciones vía `POST`:

| Acción | Descripción | Parámetros Clave |
| --- | --- | --- |
| `validate` | Verifica si el usuario existe. | `waId` |
| `create_ticket` | Crea un ticket (soporta Guests). | `waId`, `comment`, `guest_name`, `guest_email` |
| `get_user_tickets` | Lista los tickets abiertos del usuario. | `waId` |
| `add_followup` | Agrega un comentario a un ticket. | `ticket_id`, `user_id`, `comment` |

---

## 🧹 Limpieza de Datos

El plugin incluye la función `plugin_watiplugin_clean_content`, que transforma automáticamente el HTML complejo de GLPI en **texto plano optimizado para WhatsApp**, manteniendo saltos de línea y eliminando etiquetas innecesarias.

---

## 📄 Licencia

MIT - Desarrollado para mejorar la eficiencia en Mesas de Ayuda.

---

### ¿Por qué usar este plugin?

* **Reducción de SLA:** Los técnicos responden más rápido a alertas de WhatsApp que a correos.
* **Satisfacción del Cliente:** El usuario siente un soporte cercano y moderno.
* **Eficiencia:** Menos llamadas telefónicas para preguntar "¿Cómo va mi ticket?".

---

¡Excelente idea! Las **FAQ** resuelven dudas antes de que se conviertan en tickets de soporte para ti, y la **guía visual** es lo que la mayoría de los administradores buscan para no cometer errores de sintaxis.

Aquí tienes los bloques para añadir a tu **README.md**:

---

## ❓ Preguntas Frecuentes (FAQ)

**1. ¿El plugin funciona con versiones anteriores a GLPI 10?**
No, este plugin está diseñado específicamente para aprovechar la arquitectura de hooks y la API de **GLPI 10**.

**2. ¿Por qué mi técnico no recibe notificaciones?**
Revisa que el número de teléfono en su perfil de GLPI incluya el código de país (ej. `521...`) y que no tenga espacios ni guiones. También verifica los logs en `files/_log/watiplugin.log`.

**3. ¿Puedo personalizar el mensaje que llega a WhatsApp?**
¡Sí! Puedes editar las plantillas directamente en las funciones `plugin_watiplugin_notification_...` dentro del archivo `hook.php`.

**4. ¿Qué pasa si WATI no está disponible?**
El plugin utiliza `curl` con un *timeout* breve. Si la API de WATI no responde, GLPI continuará funcionando normalmente para no afectar la operación del usuario, y se registrará el error en el log.

---

## 🤖 Guía de Configuración en WATI Flow Builder

Para que el bot interactúe con GLPI, usa el nodo **API Connector**. Aquí tienes los JSON exactos que debes copiar y pegar:

### A. Consultar Status de Tickets

Úsalo cuando el usuario pulsa un botón de "Ver mis reportes".

* **Method:** `POST`
* **URL:** `https://tu-glpi.com/plugins/watiplugin/webhook.php`
* **Body (JSON):**

```json
{
  "action": "get_user_tickets",
  "waId": "{{waId}}"
}

```

> **Tip:** Mapea la respuesta `message` a una variable de WATI para mostrarla directamente en un mensaje.

### B. Crear un Ticket (Usuario Nuevo/Guest)

Úsalo después de pedirle el nombre y correo al usuario.

* **Body (JSON):**

```json
{
  "action": "create_ticket",
  "waId": "{{waId}}",
  "is_guest": true,
  "guest_name": "{{nombre_capturado}}",
  "guest_email": "{{correo_capturado}}",
  "comment": "{{problema_del_usuario}}"
}

```

### C. Agregar un Seguimiento

Úsalo cuando el usuario responde a una notificación de ticket existente.

* **Body (JSON):**

```json
{
  "action": "add_followup",
  "ticket_id": "{{ticket_id_guardado}}",
  "user_id": "{{glpi_user_id}}",
  "comment": "{{ultimo_mensaje}}"
}

```

---

## 🖼️ Flujo Lógico del Bot (Esquema)

```text
[Usuario escribe] 
       |
[¿Existe en GLPI?] --(NO)--> [Pedir Nombre/Correo] --> [Crear Usuario + Ticket]
       |
      (SÍ)
       |
[Menú Principal] --> [1. Crear Ticket]
                 --> [2. Ver mis Tickets] --> [Muestra lista con último comentario]
                 --> [3. Añadir Comentario]

```

---
