<?php
/**
 * Función corregida para GLPI 10
 */
function plugin_watiplugin_render_content($template_id, $ticket) {
   $template = new NotificationTemplate();
   if (!$template->getFromDB($template_id)) {
      return "Nueva actualización en su Ticket #" . $ticket->fields['id'];
   }

   // Creamos el target específico para Tickets (esto procesa las etiquetas ##)
   $target = new NotificationTargetTicket($ticket, [
      'entities_id' => $ticket->fields['entities_id'],
      'language'    => $_SESSION['glpilanguage'] ?? 'es_ES'
   ]);

   // El método correcto en GLPI 10 para obtener el texto
   $data = $template->getRenderedContent($target);

   // Retornamos el contenido en texto plano (sin HTML para WhatsApp)
   return strip_tags($data['content_text'] ?? $data['content_html'] ?? 'Actualización');
}

/**
 * Función para llamar a la API de WATI
 */
function plugin_watiplugin_call_wati_api($phone, $message, $config, $ticket_id,$name) {
    $baseUrl = rtrim($config->fields['wati_url'], '/') . "/api/v1/sendTemplateMessage?whatsappNumber=";
    $url = $baseUrl.preg_replace('/[^0-9]/', '', $phone);
    $token = $config->fields['wati_token'];
    $body = [
        "template_name" => $config->fields['wati_template_name'], // Asegúrate que este nombre coincida en WATI
        "broadcast_name" => "ticket_" . $ticket_id,
        "parameters" => [
                ["name" => "name", "value" => $name],
                ["name" => "ticket", "value" => $ticket_id." ".$message],                
                ["name" => "url", "value" => "🔗 *Ver ticket:*".$config->fields['glpi_base_url'] . "/front/ticket.form.php?id=" . $ticket_id]
                ]
        ];

    $json_data = json_encode($body);
    // --- BLOQUE DE DEPURACIÓN ---
    $headers = [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json",
        "Content-Length: " . strlen($json_data)
    ];

    // Guardamos en el log qué estamos mandando exactamente
    $debug_info = "\n--- INICIO PETICIÓN DEBUG ---\n";
    $debug_info .= "URL: $url\n";
    $debug_info .= "HEADERS: " . implode(" | ", $headers) . "\n";
    $debug_info .= "BODY: $json_data\n";
    $debug_info .= "--- FIN PETICIÓN ---\n";
    Toolbox::logInFile("watiplugin", $debug_info);
    // --- FIN BLOQUE DE DEPURACIÓN ---

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => $headers,
            'content' => $json_data,
            'ignore_errors' => true // Permite leer el cuerpo del error 400/401/404
        ]
    ];
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    $status_line = isset($http_response_header[0]) ? $http_response_header[0] : "No response";
    // 5. Manejo de logs para depuración
    if ($response === false) {
        Toolbox::logInFile("watiplugin", "Error crítico: No se pudo conectar con la API de WATI.\n");
    } else {
        $res_decoded = json_decode($response, true);
        if (isset($res_decoded['result']) && $res_decoded['result'] === 'success') {
            Toolbox::logInFile("watiplugin", "ÉXITO: Mensaje enviado a $phone para el Ticket #$ticket_id\n");
        } else {
            Toolbox::logInFile("watiplugin", "WATI API Error: " . $response . " | URL: $url\n");
            Toolbox::logInFile("watiplugin", "DETALLE DEL ERROR WATI: " . $response . " | HTTP Status: " . $status_line . "\n");        
        }
    }
}

function plugin_watiplugin_notification_assign($ticket) {
    global $DB;
    if (!($ticket instanceof Ticket) || $ticket->getType() !== 'Ticket') {
        return;
    }

    Toolbox::logInFile("watiplugin", "Hook de asignación disparado para Ticket #" . $ticket->fields['id'] . "\n");

    // El hook se dispara desde updateActors() — los actores ya están guardados en DB.
    // No se puede leer _users_id_assign desde $ticket->input en este punto del ciclo de vida.
    // Se consulta directamente la DB para obtener los técnicos asignados.
    $current_user_id = Session::getLoginUserID();
    $assigner_name = "Sistema / Automático";
    if ($current_user_id) {
        $assigner = new User();
        if ($assigner->getFromDB($current_user_id)) {
            $assigner_name = $assigner->getFriendlyName();
        }
    }
    $ticket_id = $ticket->fields['id'];
    $assigned_technicians = [];
    $iterator = $DB->request([
        'SELECT' => 'users_id',
        'FROM'   => 'glpi_tickets_users',
        'WHERE'  => [
            'tickets_id' => $ticket_id,
            'type'       => 2
        ]
    ]);
    foreach ($iterator as $data) {
        if ($data['users_id'] > 0) {
            $assigned_technicians[] = $data['users_id'];
        }
    }
    if (empty($assigned_technicians)) {
        Toolbox::logInFile("watiplugin", "Ticket #$ticket_id: sin técnicos asignados, se omite notificación.\n");
        return;
    }

    $config = new PluginWatipluginConfig();
    $config->getFromDB(1);

    // 3. Iterar sobre cada técnico asignado
    $technician = new User();
    foreach ($assigned_technicians as $tech_id) {
        try {
            if ($tech_id <= 0) continue;

            if ($technician->getFromDB($tech_id)) {
            $phone = !empty($technician->fields['mobile']) ? $technician->fields['mobile'] : $technician->fields['phone'];
            if (!empty($phone)) {
                $subject  = $ticket->fields['name'];
                $priority = Ticket::getPriorityName($ticket->fields['priority']);

                $mensaje  = "🛠️ *Asignación de Ticket #$ticket_id* 🛠️";
                $mensaje .= "Se te ha asignado un ticket con la siguiente información: ";
                $mensaje .= "👤 *Asignado por:* $assigner_name ";
                $mensaje .= "📌 *Asunto:* $subject ";
                $mensaje .= "⚠️ *Prioridad:* $priority ";
                $mensaje .= "_Por favor, revisa los detalles en GLPI._";

                $name = !empty($technician->fields['firstname']) ? $technician->fields['firstname'] : $technician->fields['name'];
                Toolbox::logInFile("watiplugin", "Ticket #$ticket_id: enviando notificación a $name ($phone)\n");
                plugin_watiplugin_call_wati_api($phone, $mensaje, $config, $ticket_id, $name);
                
            }
        }
        } catch (Throwable $e) {
            // Esto atrapará errores de PHP 7+ y excepciones, evitando que el script muera
            Toolbox::logInFile("watiplugin", "Error procesando ----Message: " . $e->getMessage());
            continue; 
        }
    }
}



// Función auxiliar para limpiar el HTML del comentario de GLPI
function plugin_watiplugin_clean_content($content) {
    $content = html_entity_decode($content);
    $content = strip_tags($content);
    return trim($content);
}

function plugin_watiplugin_notification_followup($followup) {
    global $DB;
    // 1. Verificación de tipo y privacidad
    if ($followup->fields['itemtype'] !== 'Ticket' || $followup->fields['is_private'] == 1) {
        return;
    }
    // 2. Cargar el Ticket padre
    $ticket = new Ticket();
    if (!$ticket->getFromDB($followup->fields['items_id'])) {
        return;
    }
    $author = new User();
    $author_name = "Soporte";
    $author_id = $followup->fields['users_id'];
    if ($author->getFromDB($followup->fields['users_id'])) {
        $author_name = $author->getFriendlyName();
    }
    
    $texto = strip_tags($followup->fields['content']);
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = str_replace(array("\r", "\n", "\t"), ' ', $texto);
    $clean_content = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $texto);
    $clean_content = preg_replace('/\s+/u', ' ', $clean_content);      
    $clean_content = trim($clean_content);
    $clean_content = strip_tags($clean_content);
    $mensaje_final  = "👤_{$author_name} escribió:";
    $mensaje_final .= substr($clean_content,0,100)."... _";
    $phone = plugin_watiplugin_get_requester_phone($ticket);
    $name = plugin_watiplugin_get_requester_name($ticket);
    if ($phone) {
        $config = new PluginWatipluginConfig();
        $config->getFromDB(1);
        plugin_watiplugin_call_wati_api($phone, $mensaje_final, $config, $ticket->fields['id'],$name);
    }
    Toolbox::logInFile("watiplugin", "Error crítico: Pasa el proceso del envío inicial al solicitante\n");
    $tech_id = 0;
    $iterator = $DB->request(['SELECT' => 'users_id','FROM'   => 'glpi_tickets_users','WHERE'  => ['tickets_id' => $ticket->fields['id'],'type'       => 2],'LIMIT'  => 1]);
    foreach ($iterator as $data) {
        $tech_id = $data['users_id'];
    }
    Toolbox::logInFile("watiplugin", "Error crítico: tech_id\n".$tech_id);
    Toolbox::logInFile("watiplugin", "Error crítico: tech_id\n".$author_id);
    if ($tech_id <= 0 || $author_id == $tech_id) {
        return;
    }
    
    $technician = new User();
    if ($technician->getFromDB($tech_id)) {
        $phone = !empty($technician->fields['mobile']) ? $technician->fields['mobile'] : $technician->fields['phone'];
        if (!empty($phone)) {
            $content = plugin_watiplugin_clean_content($followup->fields['content']);
            $url = rtrim($config->fields['glpi_base_url'], '/') . "/index.php?redirect=ticket_{$ticket->fields['id']}";
            $mensaje  = "💬 *Nueva respuesta en Ticket #{$ticket->fields['id']}*";
            $mensaje .= "> " . substr($content, 0, 100) . (strlen($content) > 100 ? "..." : "");
            $name = !empty($technician->fields['firstname']) ? $technician->fields['firstname'] : $technician->fields['name'];
            plugin_watiplugin_call_wati_api($phone, $mensaje, $config, $ticket->fields['id'],$name);
        }
    }

}
function plugin_watiplugin_get_requester_phone($ticket) {
    global $DB;

    // 1. Usamos Ticket_User, que es la clase específica para actores de tickets en GLPI 10
    // El tipo 1 (CommonITILActor::REQUESTER) siempre representa al Solicitante
    $ticket_user = new Ticket_User();
    
    // 2. Buscamos en la tabla de actores del ticket específico
    $iterator = $DB->request([
        'SELECT' => 'users_id',
        'FROM'   => $ticket_user->getTable(),
        'WHERE'  => [
            'tickets_id' => $ticket->fields['id'],
            'type'       => 1 // 1 = Solicitante
        ],
        'LIMIT'  => 1
    ]);

    if (count($iterator) > 0) {
        $row  = $iterator->current();
        $user = new User();
        
        if ($user->getFromDB($row['users_id'])) {
            // Prioridad: Móvil (WhatsApp), luego Teléfono
            $phone = !empty($user->fields['mobile']) ? $user->fields['mobile'] : $user->fields['phone'];
            
            if (!empty($phone)) {
                // Limpiar el número: solo dígitos y el signo +
                return preg_replace('/[^0-9+]/', '', $phone);
            }
        }
    }

    return false;
}

function plugin_watiplugin_get_requester_name($ticket) {
    global $DB;

    // 1. Usamos Ticket_User, que es la clase específica para actores de tickets en GLPI 10
    // El tipo 1 (CommonITILActor::REQUESTER) siempre representa al Solicitante
    $ticket_user = new Ticket_User();
    
    // 2. Buscamos en la tabla de actores del ticket específico
    $iterator = $DB->request([
        'SELECT' => 'users_id',
        'FROM'   => $ticket_user->getTable(),
        'WHERE'  => [
            'tickets_id' => $ticket->fields['id'],
            'type'       => 1 // 1 = Solicitante
        ],
        'LIMIT'  => 1
    ]);

    if (count($iterator) > 0) {
        $row  = $iterator->current();
        $user = new User();
        
        if ($user->getFromDB($row['users_id'])) {
            // Prioridad: Móvil (WhatsApp), luego Teléfono
            $name = !empty($user->fields['firstname']) ? $user->fields['firstname'] : $user->fields['name'];
            return $name;            
        }
    }
    return false;
}