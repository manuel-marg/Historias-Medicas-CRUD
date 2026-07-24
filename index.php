<?php
// ==========================================
// 1. LÓGICA DE BACKEND (PHP & MySQL)
// ==========================================

// Configuración de la base de datos (XAMPP por defecto)
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'historias_medicas';

// Conexión inicial a MySQL (sin seleccionar base de datos para poder crearla)
try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Crear base de datos si no existe
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    
    // Crear tabla pacientes
    $pdo->exec("CREATE TABLE IF NOT EXISTS pacientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        apellido VARCHAR(100) NOT NULL,
        cedula VARCHAR(50) NOT NULL UNIQUE,
        medico_tratante VARCHAR(150) NOT NULL,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Crear tabla fotos_historias
    $pdo->exec("CREATE TABLE IF NOT EXISTS fotos_historias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        paciente_id INT NOT NULL,
        ruta_foto VARCHAR(255) NOT NULL,
        fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE
    )");
    
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Procesamiento de solicitudes POST (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        // 1. Guardar nuevo paciente con fotos
        if ($action === 'save') {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $cedula = trim($_POST['cedula'] ?? '');
            $medico = trim($_POST['medico'] ?? '');
            $fotos = json_decode($_POST['fotos'] ?? '[]');
            
            if (empty($nombre) || empty($apellido) || empty($cedula) || empty($fotos)) {
                throw new Exception("Faltan datos obligatorios o no hay fotos capturadas.");
            }
            
            // Verificar si el paciente ya existe
            $stmt = $pdo->prepare("SELECT id FROM pacientes WHERE cedula = ?");
            $stmt->execute([$cedula]);
            $paciente = $stmt->fetch();
            
            if ($paciente) {
                throw new Exception("Ya existe un paciente registrado con esta cédula.");
            }
            
            // Insertar nuevo paciente
            $stmt = $pdo->prepare("INSERT INTO pacientes (nombre, apellido, cedula, medico_tratante) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $apellido, $cedula, $medico]);
            $paciente_id = $pdo->lastInsertId();
            
            // Crear estructura de carpetas
            $baseDir = __DIR__ . '/historias_guardadas';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0777, true);
            }
            
            $folderNameRaw = "$nombre - $cedula - $medico";
            $folderName = preg_replace('/[^a-zA-Z0-9\-\s]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $folderNameRaw));
            $targetDir = $baseDir . '/' . $folderName;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            
            // Procesar y guardar cada foto
            $stmtFoto = $pdo->prepare("INSERT INTO fotos_historias (paciente_id, ruta_foto) VALUES (?, ?)");
            foreach ($fotos as $base64String) {
                $image_parts = explode(";base64,", $base64String);
                $image_base64 = base64_decode($image_parts[1]);
                $fileName = uniqid('historia_') . '.jpg';
                $filePath = $targetDir . '/' . $fileName;
                file_put_contents($filePath, $image_base64);
                $rutaBD = 'historias_guardadas/' . $folderName . '/' . $fileName;
                $stmtFoto->execute([$paciente_id, $rutaBD]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Historia médica guardada correctamente.']);
            exit;
        }
        
        // 2. Agregar fotos a paciente existente
        if ($action === 'add_photos') {
            $paciente_id = $_POST['paciente_id'] ?? 0;
            $fotos = json_decode($_POST['fotos'] ?? '[]');
            
            if (empty($paciente_id) || empty($fotos)) {
                throw new Exception("Faltan datos obligatorios.");
            }
            
            // Obtener datos del paciente para la carpeta
            $stmt = $pdo->prepare("SELECT nombre, apellido, cedula, medico_tratante FROM pacientes WHERE id = ?");
            $stmt->execute([$paciente_id]);
            $paciente = $stmt->fetch();
            
            if (!$paciente) {
                throw new Exception("Paciente no encontrado.");
            }
            
            // Crear estructura de carpetas
            $baseDir = __DIR__ . '/historias_guardadas';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0777, true);
            }
            
            $folderNameRaw = "{$paciente['nombre']} - {$paciente['cedula']} - {$paciente['medico_tratante']}";
            $folderName = preg_replace('/[^a-zA-Z0-9\-\s]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $folderNameRaw));
            $targetDir = $baseDir . '/' . $folderName;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            
            // Procesar y guardar cada foto
            $stmtFoto = $pdo->prepare("INSERT INTO fotos_historias (paciente_id, ruta_foto) VALUES (?, ?)");
            foreach ($fotos as $base64String) {
                $image_parts = explode(";base64,", $base64String);
                $image_base64 = base64_decode($image_parts[1]);
                $fileName = uniqid('historia_') . '.jpg';
                $filePath = $targetDir . '/' . $fileName;
                file_put_contents($filePath, $image_base64);
                $rutaBD = 'historias_guardadas/' . $folderName . '/' . $fileName;
                $stmtFoto->execute([$paciente_id, $rutaBD]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Fotos agregadas correctamente.']);
            exit;
        }
        
        // 3. Editar datos del paciente
        if ($action === 'edit_patient') {
            $paciente_id = $_POST['paciente_id'] ?? 0;
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $medico = trim($_POST['medico'] ?? '');
            
            if (empty($paciente_id) || empty($nombre) || empty($apellido) || empty($medico)) {
                throw new Exception("Faltan datos obligatorios.");
            }
            
            $stmt = $pdo->prepare("UPDATE pacientes SET nombre = ?, apellido = ?, medico_tratante = ? WHERE id = ?");
            $stmt->execute([$nombre, $apellido, $medico, $paciente_id]);
            
            echo json_encode(['success' => true, 'message' => 'Datos del paciente actualizados correctamente.']);
            exit;
        }
        
        // 4. Eliminar foto específica
        if ($action === 'delete_photo') {
            $photo_id = $_POST['photo_id'] ?? 0;
            
            if (empty($photo_id)) {
                throw new Exception("ID de foto no válido.");
            }
            
            // Obtener la ruta de la foto
            $stmt = $pdo->prepare("SELECT ruta_foto FROM fotos_historias WHERE id = ?");
            $stmt->execute([$photo_id]);
            $photo = $stmt->fetch();
            
            if (!$photo) {
                throw new Exception("Foto no encontrada.");
            }
            
            // Eliminar el archivo físico
            $filePath = __DIR__ . '/' . $photo['ruta_foto'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Eliminar el registro de la base de datos
            $stmt = $pdo->prepare("DELETE FROM fotos_historias WHERE id = ?");
            $stmt->execute([$photo_id]);
            
            echo json_encode(['success' => true, 'message' => 'Foto eliminada correctamente.']);
            exit;
        }
        
        // 5. Eliminar paciente (y sus fotos por CASCADE)
        if ($action === 'delete_patient') {
            $paciente_id = $_POST['paciente_id'] ?? 0;
            
            if (empty($paciente_id)) {
                throw new Exception("ID de paciente no válido.");
            }
            
            // Obtener datos del paciente para eliminar la carpeta
            $stmt = $pdo->prepare("SELECT nombre, apellido, cedula, medico_tratante FROM pacientes WHERE id = ?");
            $stmt->execute([$paciente_id]);
            $paciente = $stmt->fetch();
            
            if (!$paciente) {
                throw new Exception("Paciente no encontrado.");
            }
            
            // Eliminar la carpeta del paciente
            $folderNameRaw = "{$paciente['nombre']} - {$paciente['cedula']} - {$paciente['medico_tratante']}";
            $folderName = preg_replace('/[^a-zA-Z0-9\-\s]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $folderNameRaw));
            $targetDir = __DIR__ . '/historias_guardadas/' . $folderName;
            
            if (is_dir($targetDir)) {
                // Eliminar todos los archivos de la carpeta
                $files = glob($targetDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($targetDir);
            }
            
            // Eliminar el paciente (y sus fotos por CASCADE)
            $stmt = $pdo->prepare("DELETE FROM pacientes WHERE id = ?");
            $stmt->execute([$paciente_id]);
            
            echo json_encode(['success' => true, 'message' => 'Paciente y sus documentos eliminados correctamente.']);
            exit;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Obtener lista de pacientes (para el frontend)
$pacientes = [];
try {
    $search = $_GET['search'] ?? '';
    $query = "SELECT * FROM pacientes";
    $params = [];
    
    if (!empty($search)) {
        $query .= " WHERE nombre LIKE ? OR apellido LIKE ? OR cedula LIKE ? OR medico_tratante LIKE ?";
        $searchParam = "%$search%";
        $params = [$searchParam, $searchParam, $searchParam, $searchParam];
    }
    
    $query .= " ORDER BY fecha_registro DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener fotos para cada paciente
    foreach ($pacientes as &$paciente) {
        $stmtFotos = $pdo->prepare("SELECT * FROM fotos_historias WHERE paciente_id = ? ORDER BY fecha_subida DESC");
        $stmtFotos->execute([$paciente['id']]);
        $paciente['fotos'] = $stmtFotos->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("Error al obtener pacientes: " . $e->getMessage());
}

// Obtener datos de un paciente específico (para el frontend)
if (isset($_GET['action']) && $_GET['action'] === 'get_patient' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $paciente_id = $_GET['id'];
        
        $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
        $stmt->execute([$paciente_id]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$paciente) {
            echo json_encode(['success' => false, 'message' => 'Paciente no encontrado.']);
            exit;
        }
        
        // Obtener fotos del paciente
        $stmtFotos = $pdo->prepare("SELECT * FROM fotos_historias WHERE paciente_id = ? ORDER BY fecha_subida DESC");
        $stmtFotos->execute([$paciente_id]);
        $paciente['fotos'] = $stmtFotos->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'patient' => $paciente]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener el paciente: ' . $e->getMessage()]);
        exit;
    }
}
?>

<!-- ========================================== -->
<!-- 2. INTERFAZ DE USUARIO (HTML, CSS & JS) -->
<!-- ========================================== -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Historias Médicas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Ajuste para que el video parezca un escáner y ocupe el ancho correcto */
        #camera-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            border-radius: 0.5rem;
            overflow: hidden;
            background-color: #000;
        }
        #camera {
            width: 100%;
            height: auto;
            display: block;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased pb-20">

    <!-- Modal para ver/editar paciente -->
    <div id="patientModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div id="modalContent"></div>
        </div>
    </div>

    <!-- Modal para confirmar eliminación -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div id="confirmContent"></div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto p-4 sm:p-6 mt-4">
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="bg-blue-600 p-4 text-white text-center">
                <h1 class="text-2xl font-bold"><i class="fas fa-notes-medical mr-2"></i> Gestión de Historias Médicas</h1>
                <p class="text-sm opacity-90">Sistema CRUD para pacientes y documentos</p>
            </div>

            <div class="p-6">
                <!-- Barra de búsqueda y botón para nuevo paciente -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Buscar Paciente</label>
                        <div class="relative">
                            <input type="text" id="searchInput" 
                                   class="w-full border border-gray-300 rounded-md p-2 pl-10 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Buscar por nombre, apellido, cédula o médico...">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                    <div class="flex items-end">
                        <button id="btn-new-patient" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md transition duration-200">
                            <i class="fas fa-plus mr-1"></i> Nuevo Paciente
                        </button>
                    </div>
                </div>

                <!-- Listado de pacientes -->
                <div id="patientsList" class="space-y-4">
                    <!-- Los pacientes se cargarán aquí dinámicamente -->
                </div>

                <!-- Sección para nuevo paciente (oculta por defecto) -->
                <div id="newPatientSection" class="hidden">
                    <hr class="my-6">
                    <h2 class="text-lg font-semibold mb-4"><i class="fas fa-user-plus mr-2"></i> Registrar Nuevo Paciente</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                            <input type="text" id="nombre" class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. Juan">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Apellido</label>
                            <input type="text" id="apellido" class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. Pérez">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cédula de Identidad</label>
                            <input type="text" id="cedula" class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. 12345678">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Médico Tratante</label>
                            <input type="text" id="medico" class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. Dr. Silva">
                        </div>
                    </div>

                    <!-- Sección de Cámara -->
                    <div class="text-center mb-4">
                        <h3 class="text-lg font-semibold mb-2"><i class="fas fa-camera text-blue-600"></i> Escáner de Documentos</h3>
                        <button id="btn-start-camera" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-md transition duration-200">
                            <i class="fas fa-video mr-1"></i> Iniciar Cámara
                        </button>
                    </div>

                    <div id="camera-container" class="hidden mb-4 shadow-inner">
                        <video id="camera" autoplay playsinline></video>
                        <div class="absolute inset-0 border-4 border-blue-500/30 m-4 rounded pointer-events-none"></div>
                    </div>

                    <div class="text-center hidden" id="capture-controls">
                        <button id="btn-capture" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-full shadow-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-camera mr-2"></i> Tomar Foto
                        </button>
                    </div>

                    <!-- Canvas oculto -->
                    <canvas id="canvas" class="hidden"></canvas>

                    <!-- Galería Preliminar -->
                    <div class="mt-8" id="gallery-section" style="display: none;">
                        <h3 class="text-md font-semibold text-gray-700 mb-3 border-b pb-2">Fotos Capturadas (<span id="photo-count">0</span>)</h3>
                        <div id="gallery" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                        </div>
                    </div>

                    <!-- Botones de acción -->
                    <div class="mt-8 text-center border-t pt-6" id="save-section" style="display: none;">
                        <div class="flex flex-col sm:flex-row gap-4 justify-center">
                            <button id="btn-save-all" class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-md shadow-md text-lg transition duration-200">
                                <i class="fas fa-save mr-2"></i> Guardar Paciente
                            </button>
                            <button id="btn-cancel-new" class="bg-gray-500 hover:bg-gray-600 text-white font-bold px-8 py-3 rounded-md shadow-md text-lg transition duration-200">
                                <i class="fas fa-times mr-2"></i> Cancelar
                            </button>
                        </div>
                        <p id="status-msg" class="mt-3 text-sm font-medium"></p>
                    </div>
                </div>

                <!-- Sección para agregar fotos a paciente existente (oculta por defecto) -->
                <div id="addPhotosSection" class="hidden">
                    <hr class="my-6">
                    <h2 class="text-lg font-semibold mb-4"><i class="fas fa-images mr-2"></i> Agregar Nuevos Documentos</h2>
                    <input type="hidden" id="currentPatientId">
                    
                    <!-- Sección de Cámara -->
                    <div class="text-center mb-4">
                        <button id="btn-start-camera-add" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-md transition duration-200">
                            <i class="fas fa-video mr-1"></i> Iniciar Cámara
                        </button>
                    </div>

                    <div id="camera-container-add" class="hidden mb-4 shadow-inner">
                        <video id="camera-add" autoplay playsinline></video>
                        <div class="absolute inset-0 border-4 border-blue-500/30 m-4 rounded pointer-events-none"></div>
                    </div>

                    <div class="text-center hidden" id="capture-controls-add">
                        <button id="btn-capture-add" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-full shadow-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-camera mr-2"></i> Tomar Foto
                        </button>
                    </div>

                    <!-- Canvas oculto -->
                    <canvas id="canvas-add" class="hidden"></canvas>

                    <!-- Galería Preliminar -->
                    <div class="mt-8" id="gallery-section-add" style="display: none;">
                        <h3 class="text-md font-semibold text-gray-700 mb-3 border-b pb-2">Nuevas Fotos (<span id="photo-count-add">0</span>)</h3>
                        <div id="gallery-add" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                        </div>
                    </div>

                    <!-- Botones de acción -->
                    <div class="mt-8 text-center border-t pt-6" id="save-section-add" style="display: none;">
                        <div class="flex flex-col sm:flex-row gap-4 justify-center">
                            <button id="btn-save-add" class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-md shadow-md text-lg transition duration-200">
                                <i class="fas fa-save mr-2"></i> Guardar Fotos
                            </button>
                            <button id="btn-cancel-add" class="bg-gray-500 hover:bg-gray-600 text-white font-bold px-8 py-3 rounded-md shadow-md text-lg transition duration-200">
                                <i class="fas fa-times mr-2"></i> Cancelar
                            </button>
                        </div>
                        <p id="status-msg-add" class="mt-3 text-sm font-medium"></p>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // ==========================================
        // VARIABLES GLOBALES
        // ==========================================
        
        // Elementos del DOM
        const video = document.getElementById('camera');
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        const gallery = document.getElementById('gallery');
        const capturedPhotos = [];
        
        // Para agregar fotos a paciente existente
        const videoAdd = document.getElementById('camera-add');
        const canvasAdd = document.getElementById('canvas-add');
        const ctxAdd = canvasAdd.getContext('2d');
        const galleryAdd = document.getElementById('gallery-add');
        const capturedPhotosAdd = [];
        
        // Botones y contenedores
        const btnStartCamera = document.getElementById('btn-start-camera');
        const btnCapture = document.getElementById('btn-capture');
        const btnSaveAll = document.getElementById('btn-save-all');
        const cameraContainer = document.getElementById('camera-container');
        const captureControls = document.getElementById('capture-controls');
        const gallerySection = document.getElementById('gallery-section');
        const saveSection = document.getElementById('save-section');
        const photoCountEl = document.getElementById('photo-count');
        const statusMsg = document.getElementById('status-msg');
        
        // Para agregar fotos
        const btnStartCameraAdd = document.getElementById('btn-start-camera-add');
        const btnCaptureAdd = document.getElementById('btn-capture-add');
        const btnSaveAdd = document.getElementById('btn-save-add');
        const cameraContainerAdd = document.getElementById('camera-container-add');
        const captureControlsAdd = document.getElementById('capture-controls-add');
        const gallerySectionAdd = document.getElementById('gallery-section-add');
        const saveSectionAdd = document.getElementById('save-section-add');
        const photoCountElAdd = document.getElementById('photo-count-add');
        const statusMsgAdd = document.getElementById('status-msg-add');
        
        // Secciones
        const newPatientSection = document.getElementById('newPatientSection');
        const addPhotosSection = document.getElementById('addPhotosSection');
        const patientsList = document.getElementById('patientsList');
        const searchInput = document.getElementById('searchInput');
        const btnNewPatient = document.getElementById('btn-new-patient');
        const btnCancelNew = document.getElementById('btn-cancel-new');
        const btnCancelAdd = document.getElementById('btn-cancel-add');
        
        // Modales
        const patientModal = document.getElementById('patientModal');
        const confirmModal = document.getElementById('confirmModal');
        const modalContent = document.getElementById('modalContent');
        const confirmContent = document.getElementById('confirmContent');
        
        // Streams de cámara
        let stream = null;
        let streamAdd = null;
        
        // ==========================================
        // FUNCIONES DE CÁMARA
        // ==========================================
        
        async function startCamera(videoElement, container, controls, startButton) {
            try {
                const newStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
                    audio: false
                });
                
                videoElement.srcObject = newStream;
                container.classList.remove('hidden');
                controls.classList.remove('hidden');
                startButton.classList.add('hidden');
                
                return newStream;
            } catch (err) {
                console.error("Error al acceder a la cámara:", err);
                alert("No se pudo acceder a la cámara. Si estás en el teléfono, necesitas acceder mediante HTTPS (ngrok) o permitir los permisos del navegador.");
                return null;
            }
        }
        
        function stopCamera(streamToStop) {
            if (streamToStop) {
                streamToStop.getTracks().forEach(track => track.stop());
            }
        }
        
        function capturePhoto(videoElement, canvasElement, ctxElement, photosArray, updateGalleryFn) {
            // Efecto de flash visual rápido
            videoElement.parentElement.style.opacity = '0.3';
            setTimeout(() => videoElement.parentElement.style.opacity = '1', 150);
            
            // Ajustar canvas a la resolución real del video
            canvasElement.width = videoElement.videoWidth;
            canvasElement.height = videoElement.videoHeight;
            
            // Dibujar el fotograma actual en el canvas
            ctxElement.drawImage(videoElement, 0, 0, canvasElement.width, canvasElement.height);
            
            // Convertir a Base64 (JPG al 70% de calidad)
            const base64Data = canvasElement.toDataURL('image/jpeg', 0.7);
            
            // Guardar en el arreglo
            photosArray.push(base64Data);
            
            // Actualizar UI
            updateGalleryFn();
        }
        
        function updateGallery(photosArray, galleryElement, countElement, gallerySection, saveSection) {
            gallerySection.style.display = 'block';
            saveSection.style.display = 'block';
            countElement.textContent = photosArray.length;
            
            // Limpiar galería actual
            galleryElement.innerHTML = '';
            
            // Renderizar miniaturas
            photosArray.forEach((photoData, index) => {
                const imgWrap = document.createElement('div');
                imgWrap.className = 'relative rounded overflow-hidden border border-gray-200 shadow-sm aspect-[3/4]';
                
                const img = document.createElement('img');
                img.src = photoData;
                img.className = 'w-full h-full object-cover';
                
                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
                deleteBtn.className = 'absolute top-1 right-1 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs opacity-80 hover:opacity-100';
                deleteBtn.onclick = () => {
                    photosArray.splice(index, 1);
                    updateGallery(photosArray, galleryElement, countElement, gallerySection, saveSection);
                    if(photosArray.length === 0) {
                        saveSection.style.display = 'none';
                        gallerySection.style.display = 'none';
                    }
                };
                
                imgWrap.appendChild(img);
                imgWrap.appendChild(deleteBtn);
                galleryElement.appendChild(imgWrap);
            });
        }
        
        // ==========================================
        // FUNCIONES DE PACIENTES
        // ==========================================
        
        async function loadPatients(search = '') {
            try {
                const url = new URL(window.location.href);
                if (search) {
                    url.searchParams.set('search', search);
                }
                
                const response = await fetch(url.toString());
                const html = await response.text();
                
                // Extraer los datos de pacientes del HTML (ya que PHP los inyecta)
                // En su lugar, haremos una petición específica para obtener los pacientes
                const patientsResponse = await fetch(`index.php?search=${encodeURIComponent(search)}`);
                const patientsText = await patientsResponse.text();
                
                // Parsear el JSON de pacientes (lo agregaremos en el backend)
                // Por ahora, recargamos la página
                window.location.search = search ? `?search=${encodeURIComponent(search)}` : '';
                
            } catch (error) {
                console.error("Error al cargar pacientes:", error);
                alert("Error al cargar la lista de pacientes.");
            }
        }
        
        function renderPatients(pacientes) {
            if (pacientes.length === 0) {
                patientsList.innerHTML = '<p class="text-center text-gray-500 py-8">No se encontraron pacientes registrados.</p>';
                return;
            }
            
            patientsList.innerHTML = '';
            
            pacientes.forEach(paciente => {
                const patientCard = document.createElement('div');
                patientCard.className = 'bg-white border border-gray-200 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow';
                
                const photoCount = paciente.fotos ? paciente.fotos.length : 0;
                
                patientCard.innerHTML = `
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-800">
                                ${paciente.nombre} ${paciente.apellido}
                                <span class="text-sm text-gray-500 ml-2">(${paciente.cedula})</span>
                            </h3>
                            <p class="text-sm text-gray-600 mt-1">
                                <i class="fas fa-user-md mr-1"></i> ${paciente.medico_tratante}
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-calendar mr-1"></i> ${new Date(paciente.fecha_registro).toLocaleDateString()}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 mt-4 sm:mt-0">
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                <i class="fas fa-images mr-1"></i> ${photoCount} documentos
                            </span>
                            <button onclick="viewPatient(${paciente.id})" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm transition">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="addPhotosToPatient(${paciente.id})" 
                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-sm transition">
                                <i class="fas fa-plus"></i>
                            </button>
                            <button onclick="confirmDeletePatient(${paciente.id})" 
                                    class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded text-sm transition">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
                
                patientsList.appendChild(patientCard);
            });
        }
        
        async function viewPatient(patientId) {
            try {
                const response = await fetch(`index.php?action=get_patient&id=${patientId}`);
                const data = await response.json();
                
                if (data.success) {
                    const paciente = data.patient;
                    
                    let html = `
                        <h2 class="text-xl font-bold mb-4"><i class="fas fa-user mr-2"></i> ${paciente.nombre} ${paciente.apellido}</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <p><strong>Cédula:</strong> ${paciente.cedula}</p>
                                <p><strong>Médico:</strong> ${paciente.medico_tratante}</p>
                                <p><strong>Fecha de registro:</strong> ${new Date(paciente.fecha_registro).toLocaleString()}</p>
                            </div>
                            <div>
                                <p><strong>Total documentos:</strong> ${paciente.fotos.length}</p>
                            </div>
                        </div>
                        <hr class="my-4">
                        <h3 class="text-lg font-semibold mb-3"><i class="fas fa-images mr-2"></i> Documentos</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-4">
                    `;
                    
                    paciente.fotos.forEach(foto => {
                        html += `
                            <div class="relative rounded overflow-hidden border border-gray-200 shadow-sm aspect-[3/4]">
                                <img src="${foto.ruta_foto}" class="w-full h-full object-cover" alt="Documento">
                                <button onclick="confirmDeletePhoto(${foto.id}, ${paciente.id})" 
                                        class="absolute top-1 right-1 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs opacity-80 hover:opacity-100">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `;
                    });
                    
                    html += `
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editPatient(${paciente.id})" 
                                    class="flex-1 bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded transition">
                                <i class="fas fa-edit mr-1"></i> Editar Datos
                            </button>
                            <button onclick="addPhotosToPatient(${paciente.id})" 
                                    class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded transition">
                                <i class="fas fa-plus mr-1"></i> Agregar Fotos
                            </button>
                        </div>
                    `;
                    
                    modalContent.innerHTML = html;
                    patientModal.style.display = 'block';
                } else {
                    alert(data.message || "Error al cargar los datos del paciente.");
                }
            } catch (error) {
                console.error("Error al ver paciente:", error);
                alert("Error al cargar los datos del paciente.");
            }
        }
        
        function editPatient(patientId) {
            // Obtener el paciente y mostrar formulario de edición
            fetch(`index.php?action=get_patient&id=${patientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const paciente = data.patient;
                        
                        let html = `
                            <h2 class="text-xl font-bold mb-4"><i class="fas fa-edit mr-2"></i> Editar Paciente</h2>
                            <form id="editPatientForm" onsubmit="saveEditPatient(${paciente.id}, event)">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                                        <input type="text" id="editNombre" value="${paciente.nombre}" 
                                               class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Apellido</label>
                                        <input type="text" id="editApellido" value="${paciente.apellido}" 
                                               class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Cédula</label>
                                        <input type="text" value="${paciente.cedula}" 
                                               class="w-full border border-gray-300 rounded-md p-2 bg-gray-100" disabled>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Médico Tratante</label>
                                        <input type="text" id="editMedico" value="${paciente.medico_tratante}" 
                                               class="w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500" required>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition">
                                        <i class="fas fa-save mr-1"></i> Guardar Cambios
                                    </button>
                                    <button type="button" onclick="closeModal()" 
                                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded transition">
                                        <i class="fas fa-times mr-1"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                        `;
                        
                        modalContent.innerHTML = html;
                        patientModal.style.display = 'block';
                    } else {
                        alert(data.message || "Error al cargar los datos del paciente.");
                    }
                })
                .catch(error => {
                    console.error("Error al editar paciente:", error);
                    alert("Error al cargar los datos del paciente.");
                });
        }
        
        function saveEditPatient(patientId, event) {
            event.preventDefault();
            
            const nombre = document.getElementById('editNombre').value;
            const apellido = document.getElementById('editApellido').value;
            const medico = document.getElementById('editMedico').value;
            
            if (!nombre || !apellido || !medico) {
                alert("Por favor completa todos los campos.");
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'edit_patient');
            formData.append('paciente_id', patientId);
            formData.append('nombre', nombre);
            formData.append('apellido', apellido);
            formData.append('medico', medico);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeModal();
                    loadPatients(searchInput.value);
                } else {
                    alert(data.message || "Error al actualizar los datos.");
                }
            })
            .catch(error => {
                console.error("Error al guardar cambios:", error);
                alert("Error al guardar los cambios.");
            });
        }
        
        function addPhotosToPatient(patientId) {
            // Mostrar la sección para agregar fotos
            document.getElementById('currentPatientId').value = patientId;
            newPatientSection.classList.add('hidden');
            addPhotosSection.classList.remove('hidden');
            
            // Limpiar fotos anteriores
            capturedPhotosAdd.length = 0;
            galleryAdd.innerHTML = '';
            gallerySectionAdd.style.display = 'none';
            saveSectionAdd.style.display = 'none';
            
            // Detener cámara si está activa
            stopCamera(stream);
            stopCamera(streamAdd);
            
            // Scroll a la sección
            addPhotosSection.scrollIntoView({ behavior: 'smooth' });
        }
        
        function confirmDeletePhoto(photoId, patientId) {
            confirmContent.innerHTML = `
                <h2 class="text-xl font-bold mb-4"><i class="fas fa-exclamation-triangle mr-2 text-red-600"></i> Confirmar Eliminación</h2>
                <p class="mb-6">¿Estás seguro de que deseas eliminar esta foto? Esta acción no se puede deshacer.</p>
                <div class="flex gap-2">
                    <button onclick="deletePhoto(${photoId}, ${patientId})" 
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded transition">
                        <i class="fas fa-trash mr-1"></i> Eliminar
                    </button>
                    <button onclick="closeConfirmModal()" 
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded transition">
                        <i class="fas fa-times mr-1"></i> Cancelar
                    </button>
                </div>
            `;
            confirmModal.style.display = 'block';
        }
        
        function deletePhoto(photoId, patientId) {
            const formData = new URLSearchParams();
            formData.append('action', 'delete_photo');
            formData.append('photo_id', photoId);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeConfirmModal();
                    viewPatient(patientId);
                } else {
                    alert(data.message || "Error al eliminar la foto.");
                }
            })
            .catch(error => {
                console.error("Error al eliminar foto:", error);
                alert("Error al eliminar la foto.");
            });
        }
        
        function confirmDeletePatient(patientId) {
            confirmContent.innerHTML = `
                <h2 class="text-xl font-bold mb-4"><i class="fas fa-exclamation-triangle mr-2 text-red-600"></i> Confirmar Eliminación</h2>
                <p class="mb-6">¿Estás seguro de que deseas eliminar este paciente y TODOS sus documentos? Esta acción no se puede deshacer.</p>
                <div class="flex gap-2">
                    <button onclick="deletePatient(${patientId})" 
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded transition">
                        <i class="fas fa-trash mr-1"></i> Eliminar Paciente
                    </button>
                    <button onclick="closeConfirmModal()" 
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded transition">
                        <i class="fas fa-times mr-1"></i> Cancelar
                    </button>
                </div>
            `;
            confirmModal.style.display = 'block';
        }
        
        function deletePatient(patientId) {
            const formData = new URLSearchParams();
            formData.append('action', 'delete_patient');
            formData.append('paciente_id', patientId);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeConfirmModal();
                    loadPatients(searchInput.value);
                } else {
                    alert(data.message || "Error al eliminar el paciente.");
                }
            })
            .catch(error => {
                console.error("Error al eliminar paciente:", error);
                alert("Error al eliminar el paciente.");
            });
        }
        
        function closeModal() {
            patientModal.style.display = 'none';
        }
        
        function closeConfirmModal() {
            confirmModal.style.display = 'none';
        }
        
        // Cerrar modales al hacer clic fuera
        window.onclick = function(event) {
            if (event.target == patientModal) {
                closeModal();
            }
            if (event.target == confirmModal) {
                closeConfirmModal();
            }
        }
        
        // ==========================================
        // EVENT LISTENERS
        // ==========================================
        
        // Búsqueda de pacientes
        searchInput.addEventListener('input', function() {
            const search = this.value;
            if (search.length > 2 || search.length === 0) {
                loadPatients(search);
            }
        });
        
        // Nuevo paciente
        btnNewPatient.addEventListener('click', () => {
            addPhotosSection.classList.add('hidden');
            newPatientSection.classList.remove('hidden');
            
            // Limpiar formulario
            document.getElementById('nombre').value = '';
            document.getElementById('apellido').value = '';
            document.getElementById('cedula').value = '';
            document.getElementById('medico').value = '';
            capturedPhotos.length = 0;
            gallery.innerHTML = '';
            gallerySection.style.display = 'none';
            saveSection.style.display = 'none';
            
            // Detener cámaras
            stopCamera(stream);
            stopCamera(streamAdd);
            
            // Mostrar botón de cámara
            btnStartCamera.classList.remove('hidden');
            cameraContainer.classList.add('hidden');
            captureControls.classList.add('hidden');
            
            newPatientSection.scrollIntoView({ behavior: 'smooth' });
        });
        
        // Cancelar nuevo paciente
        btnCancelNew.addEventListener('click', () => {
            newPatientSection.classList.add('hidden');
            stopCamera(stream);
        });
        
        // Cancelar agregar fotos
        btnCancelAdd.addEventListener('click', () => {
            addPhotosSection.classList.add('hidden');
            stopCamera(streamAdd);
        });
        
        // Iniciar cámara para nuevo paciente
        btnStartCamera.addEventListener('click', async () => {
            stream = await startCamera(video, cameraContainer, captureControls, btnStartCamera);
        });
        
        // Capturar foto para nuevo paciente
        btnCapture.addEventListener('click', () => {
            capturePhoto(video, canvas, ctx, capturedPhotos, () => {
                updateGallery(capturedPhotos, gallery, photoCountEl, gallerySection, saveSection);
            });
        });
        
        // Guardar nuevo paciente
        btnSaveAll.addEventListener('click', async () => {
            const nombre = document.getElementById('nombre').value;
            const apellido = document.getElementById('apellido').value;
            const cedula = document.getElementById('cedula').value;
            const medico = document.getElementById('medico').value;
            
            if (!nombre || !apellido || !cedula || !medico) {
                alert("Por favor completa todos los campos del paciente.");
                return;
            }
            if (capturedPhotos.length === 0) {
                alert("No has tomado ninguna foto.");
                return;
            }
            
            btnSaveAll.disabled = true;
            btnSaveAll.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
            statusMsg.textContent = 'Procesando archivos y subiendo al servidor...';
            statusMsg.className = "mt-3 text-sm font-medium text-blue-600";
            
            const formData = new URLSearchParams();
            formData.append('action', 'save');
            formData.append('nombre', nombre);
            formData.append('apellido', apellido);
            formData.append('cedula', cedula);
            formData.append('medico', medico);
            formData.append('fotos', JSON.stringify(capturedPhotos));
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                });
                
                const data = await response.json();
                
                if (data.success) {
                    statusMsg.textContent = '¡Paciente guardado con éxito!';
                    statusMsg.className = "mt-3 text-sm font-bold text-green-600";
                    
                    setTimeout(() => {
                        newPatientSection.classList.add('hidden');
                        stopCamera(stream);
                        loadPatients(searchInput.value);
                    }, 1500);
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                console.error(error);
                statusMsg.textContent = 'Error: ' + error.message;
                statusMsg.className = "mt-3 text-sm font-bold text-red-600";
                btnSaveAll.disabled = false;
                btnSaveAll.innerHTML = '<i class="fas fa-save mr-2"></i> Guardar Paciente';
            }
        });
        
        // Iniciar cámara para agregar fotos
        btnStartCameraAdd.addEventListener('click', async () => {
            streamAdd = await startCamera(videoAdd, cameraContainerAdd, captureControlsAdd, btnStartCameraAdd);
        });
        
        // Capturar foto para agregar a paciente existente
        btnCaptureAdd.addEventListener('click', () => {
            capturePhoto(videoAdd, canvasAdd, ctxAdd, capturedPhotosAdd, () => {
                updateGallery(capturedPhotosAdd, galleryAdd, photoCountElAdd, gallerySectionAdd, saveSectionAdd);
            });
        });
        
        // Guardar fotos adicionales
        btnSaveAdd.addEventListener('click', async () => {
            const pacienteId = document.getElementById('currentPatientId').value;
            
            if (capturedPhotosAdd.length === 0) {
                alert("No has tomado ninguna foto.");
                return;
            }
            
            btnSaveAdd.disabled = true;
            btnSaveAdd.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
            statusMsgAdd.textContent = 'Procesando archivos...';
            statusMsgAdd.className = "mt-3 text-sm font-medium text-blue-600";
            
            const formData = new URLSearchParams();
            formData.append('action', 'add_photos');
            formData.append('paciente_id', pacienteId);
            formData.append('fotos', JSON.stringify(capturedPhotosAdd));
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                });
                
                const data = await response.json();
                
                if (data.success) {
                    statusMsgAdd.textContent = '¡Fotos guardadas con éxito!';
                    statusMsgAdd.className = "mt-3 text-sm font-bold text-green-600";
                    
                    setTimeout(() => {
                        addPhotosSection.classList.add('hidden');
                        stopCamera(streamAdd);
                        capturedPhotosAdd.length = 0;
                        loadPatients(searchInput.value);
                    }, 1500);
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                console.error(error);
                statusMsgAdd.textContent = 'Error: ' + error.message;
                statusMsgAdd.className = "mt-3 text-sm font-bold text-red-600";
                btnSaveAdd.disabled = false;
                btnSaveAdd.innerHTML = '<i class="fas fa-save mr-2"></i> Guardar Fotos';
            }
        });
        
        // Cargar pacientes al inicio
        document.addEventListener('DOMContentLoaded', () => {
            // Obtener pacientes del backend
            const search = new URLSearchParams(window.location.search).get('search') || '';
            searchInput.value = search;
            
            // Renderizar pacientes con los datos que PHP ya inyectó
            const pacientes = <?php echo json_encode($pacientes); ?>;
            renderPatients(pacientes);
        });
        
        // Hacer funciones globales para los onclick
        window.viewPatient = viewPatient;
        window.editPatient = editPatient;
        window.saveEditPatient = saveEditPatient;
        window.addPhotosToPatient = addPhotosToPatient;
        window.confirmDeletePhoto = confirmDeletePhoto;
        window.deletePhoto = deletePhoto;
        window.confirmDeletePatient = confirmDeletePatient;
        window.deletePatient = deletePatient;
        window.closeModal = closeModal;
        window.closeConfirmModal = closeConfirmModal;
    </script>
</body>
</html>