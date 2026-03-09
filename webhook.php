<?php

// 1. Definir la ruta raíz de GLPI de forma absoluta
$glpi_root = '/var/www/glpi2';

// 2. Cargar el cargador de clases de Composer (CRÍTICO para GLPI 10)
if (file_exists($glpi_root . '/vendor/autoload.php')) {
    require_once $glpi_root . '/vendor/autoload.php';
}

// 3. Definir constantes necesarias antes de cargar el core
define('GLPI_ROOT', $glpi_root);
define('DO_NOT_CHECK_HTTP_REFERER', 1);

// 4. Cargar el core de GLPI
include_once ($glpi_root . "/inc/includes.php");


// 6. Leer el JSON de WATI
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);
header("Content-Type: application/json");
// 1. Identificar qué quiere hacer WATI (action)
// Puedes enviar "action": "validate" o "action": "create_ticket" desde WATI
$action = $data['action'] ?? 'validate'; 
$phone  = $data['waId'] ?? $data['senderNumber'] ?? null;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
        
if (!$phone) {
    echo json_encode(["status" => "error", "message" => "No phone provided"]);
    exit;
}
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);
// 2. Ejecutar la lógica según la acción
switch ($action) {
    case 'validate':
        validateUser($cleanPhone);
        break;

    case 'create_ticket':
        // Aquí llamarías a la función que ya teníamos para crear tickets
        // Pero pasando los datos de "Guest" si el usuario no existía
        createTicketFromWati($data, $cleanPhone);
        break;

    case 'create_guest_user':
        $userId = createGlpiUser($data, $cleanPhone);
        if ($userId) {
            echo json_encode(["status" => "success", "message" => "Usuario creado", "user_id" => $userId]);
        } else {
            echo json_encode(["status" => "error", "message" => "No se pudo crear el usuario"]);
        }
        break;
    case 'add_followup':
        addTicketFollowup($data, $cleanPhone);
        break;
    case 'get_user_tickets':
        getUserTickets($cleanPhone);
        break;    
    default:
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
        break;
}
function addTicketFollowup($data, $phone) {
    global $DB;

    $ticket_id = $data['ticket_id'] ?? null;
    $user_id   = $data['user_id']   ?? null;
    $content   = $data['comment']   ?? null;

    if (!$ticket_id || !$content) {
        echo json_encode(["status" => "error", "message" => "Faltan datos (ticket_id o comentario)"]);
        exit;
    }
    $rt = new RequestType();
    $requestTypesId = 1; // Valor por defecto (E-mail o Helpdesk)
    if ($rt->getFromDBByCrit(['name' => 'WATI'])) {
        $requestTypesId = $rt->fields['id'];
    }
    else{
        $requestTypesId = 6;
    }
    // 1. Instanciar el seguimiento
    $followup = new ITILFollowup();

    // 2. Preparar los datos del seguimiento
    $input = [
        'itemtype' => 'Ticket',
        'items_id' => $ticket_id,
        'content'  => "💬 *Enviado desde WhatsApp:*\n" . $content,
        'users_id' => $user_id, // El ID del solicitante que ya tenemos
        'is_private' => 0,      // Público para que el técnico lo vea
        'requesttypes_id' => $requestTypesId // Opcional: ID de 'WhatsApp' si lo creaste en GLPI
    ];

    // 3. Insertar en GLPI
    $followup_id = $followup->add($input);

    if ($followup_id) {
        echo json_encode([
            "status" => "success",
            "followup_id" => $followup_id,
            "message" => "Seguimiento registrado en el Ticket #$ticket_id"
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "No se pudo registrar el seguimiento"]);
    }
}
function validateUser($phone) {
    global $DB;
    
    $iterator = $DB->request([
        'SELECT' => ['id', 'firstname', 'realname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => [
            'OR' => [
                ['mobile' => ['LIKE', "%$phone%"]],
                ['phone'  => ['LIKE', "%$phone%"]]
            ]
        ],
        'LIMIT'  => 1
    ]);

    if (count($iterator) > 0) {
        $user = $iterator->current();
        echo json_encode([
            "exists"    => true,
            "user_id"   => $user['id'],
            "name"      => $user['firstname'] ?: $user['realname'],
            "message"   => "Usuario encontrado"
        ]);
    } else {
        echo json_encode([
            "exists"  => false,
            "message" => "Usuario no encontrado",
            "action_required" => "get_guest_data"
        ]);
    }
}

function createTicketFromWati($data, $phone) {
    global $DB;
    try {
        // Forzamos el uso del namespace global        
        $ticket  = new \Ticket();
        
        $text = $data['text'] ?? 'Sin mensaje';
        $name = $data['senderName'] ?? 'WhatsApp User';
        
        // 8. Buscar el usuario por móvil o teléfono
        Toolbox::logInFile("watiplugin", "Usuario waId: ID  $phone");        
        $requester_id = 2; // ID por defecto (post-only)
        
    
        $iterator = $DB->request([
            'SELECT' => ['id', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => [
                'OR' => [
                    ['mobile' => ['LIKE', "%$phone%"]],
                    ['phone'  => ['LIKE', "%$phone%"]]
                ]
            ],
            'LIMIT'  => 1
        ]);
        if (count($iterator) > 0) {
            $user = $iterator->current();
            $requester_id = $user['id'];
            Toolbox::logInFile("watiplugin", "Usuario encontrado: ID $requester_id");
        } else {
            Toolbox::logInFile("watiplugin", "No se encontró usuario para el número: $phone");
        }
        // 1. Buscar dinámicamente el ID del origen "WATI"
        $rt = new RequestType();
        $requestTypesId = 1; // Valor por defecto (E-mail o Helpdesk)

        if ($rt->getFromDBByCrit(['name' => 'WATI'])) {
            $requestTypesId = $rt->fields['id'];
        }
        else{
            $requestTypesId = 6;
        }

        // 9. Crear el Ticket
        $input = [
            'name'                => "WhatsApp: " . $name,
            'content'             => $text,
            'entities_id'         => 0,
            'type'                => 2, // Solicitud
            'requesttypes_id'     => $requestTypesId, // <--- Aquí usamos el ID detectado
            '_users_id_requester' => $requester_id,        
        ];

        $ticketID = $ticket->add($input);

        if ($ticketID) {
            // Obtenemos la fecha y hora actual del servidor
            $fechaActual = date("d/m/Y");
            $horaActual  = date("H:i:s");

            // Construimos la respuesta exitosa
            $response["status"]  = "success";
            $response["message"] = "Ticket registrado correctamente";
            $response["data"]    = [
                "ticket_number" => $ticketID,
                "date"          => $fechaActual,
                "time"          => $horaActual,
                "full_date"     => date("Y-m-d H:i:s")
            ];
        } else {
            $response["message"] = "GLPI no pudo guardar el ticket";
        }
        echo json_encode($response, JSON_PRETTY_PRINT);

    } catch (\Throwable $e) {
        Toolbox::logInFile("watiplugin", "EXCEPCIÓN".$e->getMessage());
        Toolbox::logInFile("watiplugin", "Línea".$e->getLine());        
    }
}

function createGlpiUser($data, $phone) {
    global $DB;

    $user = new User();
    
    // Preparar los datos del nuevo usuario
    // Usamos el email como nombre de usuario (login) para garantizar que sea único
    $newUser = [
        'name'      => $data['guest_name'], 
        'firstname' => $data['guest_name'],
        'mobile'    => $phone,
        'phone'     => $phone,
        'is_active' => 1,
        'auth_method' => 0, // Método de autenticación local (Internal)
        '_no_history' => true
    ];
    Toolbox::logInFile("watiplugin", "Usuario Nuevo Usuario: ID " . json_encode($newUser));       
    // 1. Insertar el usuario en la base de datos
    $userId = $user->add($newUser);

    if ($userId) {
        Toolbox::logInFile("watiplugin", "Usuario Nuevo Pasa IF userID: " . json_encode($userId));  
        // 2. Agregar el correo electrónico a la tabla de correos de GLPI
        $userEmail = new UserEmail();
        $userEmailId = $userEmail->add([
            'users_id' => $userId,
            'email'    => $data['guest_email'],
            'is_default' => 1
        ]);

        Toolbox::logInFile("watiplugin", "Usuario Nuevo userEmailId: ID " . json_encode($userEmailId));    

        // 3. Asignar perfil de "Self-Service" (ID 1 por defecto en GLPI)
        // Esto es vital para que el usuario pueda ver sus propios tickets luego
        $profileUser = new Profile_User();
        $profileUser->add([
            'users_id' => $userId,
            'profiles_id' => 1, // ID 1 suele ser 'Self-Service' o 'Post-only'
            'entities_id' => 0  // Entidad raíz
        ]);

        return $userId;
    }

    return false;
}

function getUserTickets($phone) {
    global $DB;
    Toolbox::logInFile("watiplugin", "----------getUserTickets");
    Toolbox::logInFile("watiplugin", "phone: " . json_encode($phone));
    // 1. Primero buscamos el ID del usuario por su teléfono
    $user_id = 0;
    $user_query = $DB->request([
        'SELECT' => ['id', 'firstname', 'realname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => [
            'OR' => [
                ['mobile' => ['LIKE', "%$phone%"]],
                ['phone'  => ['LIKE', "%$phone%"]]
            ]
        ],
        'LIMIT'  => 1
    ]);
    

    Toolbox::logInFile("watiplugin", "user_query: " . json_encode($user_query));
    foreach ($user_query as $u) { $user_id = $u['id']; }

    if ($user_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Usuario no identificado"]);
        exit;
    }

    Toolbox::logInFile("watiplugin", "user_id: " . json_encode($user_id));

    // 2. Buscamos tickets abiertos (Status < 5: Nuevo, Procesando, Pendiente, En espera)
    $tickets_data = [];
    $iterator = $DB->request([
        'SELECT' => ['t.id', 't.name', 't.status'],
        'FROM'   => 'glpi_tickets AS t',
        'INNER JOIN' => [
            'glpi_tickets_users AS tu' => [
                'ON' => ['t' => 'id', 'tu' => 'tickets_id']
            ]
        ],
        'WHERE'  => [
            'tu.users_id' => $user_id,
            'tu.type'     => 1, // Solicitante
            't.status'    => ['<', 5], // Abiertos (No cerrados ni resueltos)
            't.is_deleted' => 0
        ],
        'ORDER'  => 't.date_mod DESC',
        'LIMIT'  => 5 // Limitamos a los últimos 5 para no saturar el WhatsApp
    ]);

      


    foreach ($iterator as $ticket) {
        Toolbox::logInFile("watiplugin", "ticket: " . json_encode($ticket));
        // 3. Obtener el ÚLTIMO comentario (seguimiento) para cada ticket
        $last_comment = "Sin comentarios aún.";
        $f_iterator = $DB->request([
            'SELECT' => ['content'],
            'FROM'   => 'glpi_itilfollowups',
            'WHERE'  => [
                'itemtype' => 'Ticket',
                'items_id' => $ticket['id'],
                'is_private' => 0
            ],
            'ORDER'  => 'date_creation DESC',
            'LIMIT'  => 1
        ]);
        Toolbox::logInFile("watiplugin", "SELECT content FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id={$ticket['id']} AND is_private=0 ORDER BY date_creation DESC LIMIT 1");
     
        foreach ($f_iterator as $f) {
             Toolbox::logInFile("watiplugin", "f: " . json_encode($f));
            $last_comment = plugin_watiplugin_clean_content($f['content']);            
            if (strlen($last_comment) > 80) $last_comment = substr($last_comment, 0, 80) . "...";
            Toolbox::logInFile("watiplugin", "last_comment: " . json_encode($last_comment));
        }
        Toolbox::logInFile("watiplugin", "last_comment: " . json_encode($last_comment));
        // Traducir el status de GLPI a texto legible
        $status_text = Ticket::getStatus($ticket['status']);

        $tickets_data[] = [
            "id" => $ticket['id'],
            "titulo" => $ticket['name'],
            "estatus" => $status_text,
            "ultimo_comentario" => $last_comment
        ];
    }

    Toolbox::logInFile("watiplugin", "tickets_data: " . json_encode($tickets_data));

    if(count($tickets_data) === 0) {
        echo json_encode([
            "status" => "false",
            "count" => 0,
            "message" => "No se encontraron tickets abiertos para este usuario"
        ]);
        exit;
    }
    else  {
        echo json_encode([
        "status" => "success",
        "count" => count($tickets_data),
        "message" => formatTicketsForWhatsApp($tickets_data)
    ]);
    }  
    
}
function plugin_watiplugin_clean_content($content) {
    // 1. Decodificar entidades HTML (ej: &nbsp; se vuelve un espacio, &aacute; se vuelve á)
    $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

    // 2. Reemplazar etiquetas de salto de línea <br> o <p> por saltos de línea reales (\n)
    // Esto mantiene la estructura del mensaje en WhatsApp
    $content = preg_replace('/<(br|p|div)[^>]*>/i', "\n", $content);

    // 3. Eliminar todas las demás etiquetas HTML (tags)
    $content = strip_tags($content);

    // 4. Limpiar espacios en blanco extra al inicio y al final
    return trim($content);
}
function formatTicketsForWhatsApp($tickets) {
    if (empty($tickets)) {
        return "🔍 No encontramos tickets abiertos a tu nombre.";
    }

    $mensaje = "📂 *Tus Tickets Abiertos:*\n\n";

    foreach ($tickets as $t) {
        // Asignar emoji según el estatus
        $emoji = "🔹";
        $status = strtolower($t['estatus']);
        
        if (str_contains($status, 'nuevo')) $emoji = "🆕";
        if (str_contains($status, 'curso') || str_contains($status, 'asignada')) $emoji = "🛠️";
        if (str_contains($status, 'espera') || str_contains($status, 'pendiente')) $emoji = "⏳";
        if (str_contains($status, 'resuelto')) $emoji = "✅";

        $mensaje .= "🎫 *#{$t['id']}* - {$t['titulo']}\n";
        $mensaje .= "$emoji *Estatus:* {$t['estatus']}\n";
        $mensaje .= "💬 *Último:* {$t['ultimo_comentario']}\n";
        $mensaje .= "--------------------------\n\n";
    }    
    return $mensaje;
}