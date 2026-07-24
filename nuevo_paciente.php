<?php
// ==========================================
// LÓGICA DE BACKEND
// ==========================================

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'historias_medicas';

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS pacientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        apellido VARCHAR(100) NOT NULL,
        cedula VARCHAR(50) NOT NULL UNIQUE,
        medico_tratante VARCHAR(150) NOT NULL,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
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

// Procesamiento POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $medico = trim($_POST['medico'] ?? '');
        $fotos = json_decode($_POST['fotos'] ?? '[]');
        
        if (empty($nombre) || empty($apellido) || empty($cedula) || empty($fotos)) {
            throw new Exception("Faltan datos obligatorios o no hay fotos capturadas.");
        }
        
        $stmt = $pdo->prepare("SELECT id FROM pacientes WHERE cedula = ?");
        $stmt->execute([$cedula]);
        $paciente = $stmt->fetch();
        
        if ($paciente) {
            throw new Exception("Ya existe un paciente registrado con esta cédula.");
        }
        
        $stmt = $pdo->prepare("INSERT INTO pacientes (nombre, apellido, cedula, medico_tratante) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nombre, $apellido, $cedula, $medico]);
        $paciente_id = $pdo->lastInsertId();
        
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
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Paciente</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
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
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased pb-20 min-h-screen">

    <div class="max-w-3xl mx-auto p-4 sm:p-6 mt-4">

        <!-- Botón volver -->
        <a href="index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-4 transition">
            <i class="fas fa-arrow-left mr-2"></i> Volver al listado
        </a>

        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="bg-blue-600 p-4 text-white text-center">
                <h1 class="text-2xl font-bold"><i class="fas fa-user-plus mr-2"></i> Nuevo Paciente</h1>
                <p class="text-sm opacity-90">Completa los datos y captura los documentos</p>
            </div>

            <div class="p-6">

                <!-- Mensaje de estado general -->
                <div id="global-status" class="hidden mb-4 p-3 rounded-md text-sm font-medium"></div>

                <!-- Formulario de Datos -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                        <input type="text" id="nombre" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. Juan">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Apellido <span class="text-red-500">*</span></label>
                        <input type="text" id="apellido" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. Pérez">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cédula de Identidad <span class="text-red-500">*</span></label>
                        <input type="text" id="cedula" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. 12345678">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Médico Tratante <span class="text-red-500">*</span></label>
                        <input type="text" id="medico" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej. Dr. Silva">
                    </div>
                </div>

                <hr class="my-6">

                <!-- Sección de Cámara -->
                <div class="text-center mb-4">
                    <h2 class="text-lg font-semibold mb-3"><i class="fas fa-camera text-blue-600 mr-1"></i> Escáner de Documentos</h2>
                    <p class="text-sm text-gray-500 mb-4">Captura las fotos de la historia médica del paciente</p>
                    <button id="btn-start-camera" class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 rounded-md transition duration-200">
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
                    <button id="btn-stop-camera" class="ml-3 bg-red-500 hover:bg-red-600 text-white font-bold px-5 py-3 rounded-full shadow-lg transition duration-200">
                        <i class="fas fa-stop mr-2"></i> Detener Cámara
                    </button>
                </div>

                <canvas id="canvas" class="hidden"></canvas>

                <!-- Galería Preliminar -->
                <div class="mt-8" id="gallery-section" style="display: none;">
                    <div class="flex items-center justify-between border-b pb-2 mb-3">
                        <h3 class="text-md font-semibold text-gray-700">Fotos Capturadas (<span id="photo-count">0</span>)</h3>
                        <span class="text-xs text-gray-400">Toca la X para eliminar</span>
                    </div>
                    <div id="gallery" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3"></div>
                </div>

                <!-- Botones de acción -->
                <div class="mt-8 text-center border-t pt-6" id="save-section" style="display: none;">
                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                        <button id="btn-save-all" class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-md shadow-md text-lg transition duration-200">
                            <i class="fas fa-save mr-2"></i> Guardar Paciente
                        </button>
                        <button id="btn-reset" class="bg-orange-500 hover:bg-orange-600 text-white font-bold px-6 py-3 rounded-md shadow-md text-lg transition duration-200">
                            <i class="fas fa-redo mr-2"></i> Empezar de Nuevo
                        </button>
                    </div>
                    <p id="status-msg" class="mt-3 text-sm font-medium"></p>
                </div>

            </div>
        </div>
    </div>

    <script>
        const video = document.getElementById('camera');
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        const gallery = document.getElementById('gallery');
        const capturedPhotos = [];

        const btnStartCamera = document.getElementById('btn-start-camera');
        const btnCapture = document.getElementById('btn-capture');
        const btnStopCamera = document.getElementById('btn-stop-camera');
        const btnSaveAll = document.getElementById('btn-save-all');
        const btnReset = document.getElementById('btn-reset');
        const cameraContainer = document.getElementById('camera-container');
        const captureControls = document.getElementById('capture-controls');
        const gallerySection = document.getElementById('gallery-section');
        const saveSection = document.getElementById('save-section');
        const photoCountEl = document.getElementById('photo-count');
        const statusMsg = document.getElementById('status-msg');
        const globalStatus = document.getElementById('global-status');

        let stream = null;

        function showStatus(msg, type) {
            globalStatus.textContent = msg;
            globalStatus.className = 'mb-4 p-3 rounded-md text-sm font-medium ' +
                (type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');
            globalStatus.classList.remove('hidden');
        }

        // Iniciar cámara
        btnStartCamera.addEventListener('click', async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
                    audio: false
                });
                video.srcObject = stream;
                cameraContainer.classList.remove('hidden');
                captureControls.classList.remove('hidden');
                btnStartCamera.classList.add('hidden');
            } catch (err) {
                console.error("Error al acceder a la cámara:", err);
                alert("No se pudo acceder a la cámara. Si estás en el teléfono, necesitas HTTPS o permitir permisos.");
            }
        });

        // Detener cámara
        btnStopCamera.addEventListener('click', () => {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            cameraContainer.classList.add('hidden');
            captureControls.classList.add('hidden');
            btnStartCamera.classList.remove('hidden');
        });

        // Capturar foto
        btnCapture.addEventListener('click', () => {
            cameraContainer.style.opacity = '0.3';
            setTimeout(() => cameraContainer.style.opacity = '1', 150);

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            const base64Data = canvas.toDataURL('image/jpeg', 0.7);
            capturedPhotos.push(base64Data);
            updateGallery();
        });

        // Actualizar galería
        function updateGallery() {
            gallerySection.style.display = 'block';
            saveSection.style.display = 'block';
            photoCountEl.textContent = capturedPhotos.length;

            gallery.innerHTML = '';

            capturedPhotos.forEach((photoData, index) => {
                const imgWrap = document.createElement('div');
                imgWrap.className = 'relative rounded overflow-hidden border border-gray-200 shadow-sm aspect-[3/4]';

                const img = document.createElement('img');
                img.src = photoData;
                img.className = 'w-full h-full object-cover';

                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
                deleteBtn.className = 'absolute top-1 right-1 bg-red-500 text-white rounded-full w-7 h-7 flex items-center justify-center text-xs opacity-80 hover:opacity-100 shadow';
                deleteBtn.onclick = () => {
                    capturedPhotos.splice(index, 1);
                    updateGallery();
                    if (capturedPhotos.length === 0) {
                        saveSection.style.display = 'none';
                        gallerySection.style.display = 'none';
                    }
                };

                const numLabel = document.createElement('span');
                numLabel.textContent = index + 1;
                numLabel.className = 'absolute bottom-1 left-1 bg-black/50 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center';

                imgWrap.appendChild(img);
                imgWrap.appendChild(deleteBtn);
                imgWrap.appendChild(numLabel);
                gallery.appendChild(imgWrap);
            });
        }

        // Guardar paciente
        btnSaveAll.addEventListener('click', async () => {
            const nombre = document.getElementById('nombre').value.trim();
            const apellido = document.getElementById('apellido').value.trim();
            const cedula = document.getElementById('cedula').value.trim();
            const medico = document.getElementById('medico').value.trim();

            if (!nombre || !apellido || !cedula || !medico) {
                alert("Por favor completa todos los campos del paciente.");
                return;
            }
            if (capturedPhotos.length === 0) {
                alert("No has tomado ninguna foto.");
                return;
            }

            globalStatus.classList.add('hidden');
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
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });

                const data = await response.json();

                if (data.success) {
                    statusMsg.textContent = '¡Paciente guardado con éxito!';
                    statusMsg.className = "mt-3 text-sm font-bold text-green-600";

                    setTimeout(() => {
                        window.location.href = 'index.php?success=1';
                    }, 1200);
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

        // Resetear formulario
        btnReset.addEventListener('click', () => {
            if (!confirm("¿Estás seguro? Se borrarán todos los datos y fotos capturadas.")) return;

            document.getElementById('nombre').value = '';
            document.getElementById('apellido').value = '';
            document.getElementById('cedula').value = '';
            document.getElementById('medico').value = '';
            capturedPhotos.length = 0;
            updateGallery();
            gallerySection.style.display = 'none';
            saveSection.style.display = 'none';
            statusMsg.textContent = '';
            globalStatus.classList.add('hidden');

            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            cameraContainer.classList.add('hidden');
            captureControls.classList.add('hidden');
            btnStartCamera.classList.remove('hidden');

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    </script>
</body>
</html>
