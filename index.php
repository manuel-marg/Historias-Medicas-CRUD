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

    <!-- Banner de éxito al crear paciente -->
    <div id="success-banner" class="hidden max-w-6xl mx-auto p-4 sm:p-6 pt-4">
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg flex items-center justify-between">
            <span><i class="fas fa-check-circle mr-2"></i> Paciente registrado correctamente.</span>
            <button onclick="this.parentElement.parentElement.classList.add('hidden')" class="text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Banner de eliminación -->
    <div id="deleted-banner" class="hidden max-w-6xl mx-auto p-4 sm:p-6 pt-4">
        <div class="bg-orange-100 border border-orange-300 text-orange-700 px-4 py-3 rounded-lg flex items-center justify-between">
            <span><i class="fas fa-check-circle mr-2"></i> Paciente eliminado correctamente.</span>
            <button onclick="this.parentElement.parentElement.classList.add('hidden')" class="text-orange-700 hover:text-orange-900">
                <i class="fas fa-times"></i>
            </button>
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
                        <a href="nuevo_paciente.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md transition duration-200 inline-block">
                            <i class="fas fa-plus mr-1"></i> Nuevo Paciente
                        </a>
                    </div>
                </div>

                <!-- Listado de pacientes -->
                <div id="patientsList" class="space-y-4">
                    <!-- Los pacientes se cargarán aquí dinámicamente -->
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
        
        // Para agregar fotos a paciente existente
        const videoAdd = document.getElementById('camera-add');
        const canvasAdd = document.getElementById('canvas-add');
        const ctxAdd = canvasAdd.getContext('2d');
        const galleryAdd = document.getElementById('gallery-add');
        const capturedPhotosAdd = [];
        
        // Botones y contenedores para agregar fotos
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
        const addPhotosSection = document.getElementById('addPhotosSection');
        const patientsList = document.getElementById('patientsList');
        const searchInput = document.getElementById('searchInput');
        const btnCancelAdd = document.getElementById('btn-cancel-add');
        
        // Modales
        const confirmModal = document.getElementById('confirmModal');
        const confirmContent = document.getElementById('confirmContent');
        
        // Streams de cámara
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
            videoElement.parentElement.style.opacity = '0.3';
            setTimeout(() => videoElement.parentElement.style.opacity = '1', 150);
            
            canvasElement.width = videoElement.videoWidth;
            canvasElement.height = videoElement.videoHeight;
            ctxElement.drawImage(videoElement, 0, 0, canvasElement.width, canvasElement.height);
            
            const base64Data = canvasElement.toDataURL('image/jpeg', 0.7);
            photosArray.push(base64Data);
            updateGalleryFn();
        }
        
        function updateGalleryAdd() {
            gallerySectionAdd.style.display = 'block';
            saveSectionAdd.style.display = 'block';
            photoCountElAdd.textContent = capturedPhotosAdd.length;
            
            galleryAdd.innerHTML = '';
            
            capturedPhotosAdd.forEach((photoData, index) => {
                const imgWrap = document.createElement('div');
                imgWrap.className = 'relative rounded overflow-hidden border border-gray-200 shadow-sm aspect-[3/4]';
                
                const img = document.createElement('img');
                img.src = photoData;
                img.className = 'w-full h-full object-cover';
                
                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
                deleteBtn.className = 'absolute top-1 right-1 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs opacity-80 hover:opacity-100';
                deleteBtn.onclick = () => {
                    capturedPhotosAdd.splice(index, 1);
                    updateGalleryAdd();
                    if (capturedPhotosAdd.length === 0) {
                        saveSectionAdd.style.display = 'none';
                        gallerySectionAdd.style.display = 'none';
                    }
                };
                
                imgWrap.appendChild(img);
                imgWrap.appendChild(deleteBtn);
                galleryAdd.appendChild(imgWrap);
            });
        }
        
        // ==========================================
        // FUNCIONES DE PACIENTES
        // ==========================================
        
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
                                <i class="fas fa-images mr-1"></i> ${photoCount} docs
                            </span>
                            <a href="ver_paciente.php?id=${paciente.id}" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm transition">
                                <i class="fas fa-eye"></i>
                            </a>
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
        
        function addPhotosToPatient(patientId) {
            document.getElementById('currentPatientId').value = patientId;
            addPhotosSection.classList.remove('hidden');
            
            capturedPhotosAdd.length = 0;
            galleryAdd.innerHTML = '';
            gallerySectionAdd.style.display = 'none';
            saveSectionAdd.style.display = 'none';
            
            stopCamera(streamAdd);
            addPhotosSection.scrollIntoView({ behavior: 'smooth' });
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
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeConfirmModal();
                    reloadPatients();
                } else {
                    alert(data.message || "Error al eliminar el paciente.");
                }
            })
            .catch(error => {
                console.error("Error al eliminar paciente:", error);
                alert("Error al eliminar el paciente.");
            });
        }
        
        function closeConfirmModal() {
            confirmModal.style.display = 'none';
        }
        
        function reloadPatients() {
            const search = searchInput.value;
            const searchParam = search ? `?search=${encodeURIComponent(search)}` : '';
            window.location.search = searchParam;
        }
        
        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            if (event.target == confirmModal) closeConfirmModal();
        }
        
        // Búsqueda de pacientes
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const search = this.value;
            searchTimeout = setTimeout(() => {
                reloadPatients();
            }, 400);
        });
        
        // ==========================================
        // EVENT LISTENERS - AGREGAR FOTOS
        // ==========================================
        
        btnStartCameraAdd.addEventListener('click', async () => {
            streamAdd = await startCamera(videoAdd, cameraContainerAdd, captureControlsAdd, btnStartCameraAdd);
        });
        
        btnCaptureAdd.addEventListener('click', () => {
            capturePhoto(videoAdd, canvasAdd, ctxAdd, capturedPhotosAdd, updateGalleryAdd);
        });
        
        btnCancelAdd.addEventListener('click', () => {
            addPhotosSection.classList.add('hidden');
            stopCamera(streamAdd);
        });
        
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
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
                        reloadPatients();
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
        
        // ==========================================
        // INICIALIZACIÓN
        // ==========================================
        
        document.addEventListener('DOMContentLoaded', () => {
            const search = new URLSearchParams(window.location.search).get('search') || '';
            searchInput.value = search;
            
            // Mostrar banner de éxito si se creó un paciente
            const success = new URLSearchParams(window.location.search).get('success');
            if (success === '1') {
                document.getElementById('success-banner').classList.remove('hidden');
                setTimeout(() => {
                    document.getElementById('success-banner').classList.add('hidden');
                }, 5000);
                window.history.replaceState({}, '', 'index.php');
            }

            // Mostrar banner si se eliminó un paciente
            const deleted = new URLSearchParams(window.location.search).get('deleted');
            if (deleted === '1') {
                document.getElementById('deleted-banner').classList.remove('hidden');
                setTimeout(() => {
                    document.getElementById('deleted-banner').classList.add('hidden');
                }, 5000);
                window.history.replaceState({}, '', 'index.php');
            }
            
            const pacientes = <?php echo json_encode($pacientes); ?>;
            renderPatients(pacientes);
        });
        
        // Hacer funciones globales
        window.addPhotosToPatient = addPhotosToPatient;
        window.confirmDeletePatient = confirmDeletePatient;
        window.deletePatient = deletePatient;
        window.closeConfirmModal = closeConfirmModal;
    </script>
</body>
</html>