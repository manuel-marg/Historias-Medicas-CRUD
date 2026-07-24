<?php
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

$paciente_id = $_GET['id'] ?? 0;

if (!$paciente_id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
$stmt->execute([$paciente_id]);
$paciente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$paciente) {
    header('Location: index.php');
    exit;
}

// Procesar guardado de fotos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $fotos = json_decode($_POST['fotos'] ?? '[]');

        if (empty($fotos)) {
            throw new Exception("No hay fotos para guardar.");
        }

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

        echo json_encode(['success' => true, 'message' => 'Fotos guardadas correctamente.']);
        exit;

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Contar fotos actuales
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM fotos_historias WHERE paciente_id = ?");
$stmtCount->execute([$paciente_id]);
$fotoCount = $stmtCount->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar Fotos - <?php echo htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']); ?></title>
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

        <!-- Volver -->
        <a href="ver_paciente.php?id=<?php echo $paciente_id; ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-4 transition">
            <i class="fas fa-arrow-left mr-2"></i> Volver a <?php echo htmlspecialchars($paciente['nombre']); ?>
        </a>

        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="bg-green-600 p-5 text-white">
                <h1 class="text-2xl font-bold"><i class="fas fa-camera mr-2"></i> Agregar Documentos</h1>
                <p class="text-sm opacity-90 mt-1">
                    <?php echo htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']); ?>
                    <span class="ml-2 bg-white/20 px-2 py-0.5 rounded-full text-xs">
                        <?php echo $fotoCount; ?> documentos actuales
                    </span>
                </p>
            </div>

            <div class="p-6">

                <!-- Cámara -->
                <div class="text-center mb-4">
                    <button id="btn-start-camera" class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 rounded-md transition duration-200">
                        <i class="fas fa-video mr-1"></i> Iniciar Cámara
                    </button>
                </div>

                <div id="camera-container" class="hidden mb-4 shadow-inner">
                    <video id="camera" autoplay playsinline></video>
                    <div class="absolute inset-0 border-4 border-green-500/30 m-4 rounded pointer-events-none"></div>
                </div>

                <div class="text-center hidden" id="capture-controls">
                    <button id="btn-capture" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-full shadow-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-camera mr-2"></i> Tomar Foto
                    </button>
                    <button id="btn-stop-camera" class="ml-3 bg-red-500 hover:bg-red-600 text-white font-bold px-5 py-3 rounded-full shadow-lg transition duration-200">
                        <i class="fas fa-stop mr-2"></i> Detener
                    </button>
                </div>

                <canvas id="canvas" class="hidden"></canvas>

                <!-- Galería -->
                <div class="mt-8" id="gallery-section" style="display: none;">
                    <div class="flex items-center justify-between border-b pb-2 mb-3">
                        <h3 class="text-md font-semibold text-gray-700">Nuevas Fotos (<span id="photo-count">0</span>)</h3>
                        <span class="text-xs text-gray-400">Toca la X para eliminar</span>
                    </div>
                    <div id="gallery" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3"></div>
                </div>

                <!-- Botones -->
                <div class="mt-8 text-center border-t pt-6" id="save-section" style="display: none;">
                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                        <button id="btn-save" class="bg-green-600 hover:bg-green-700 text-white font-bold px-8 py-3 rounded-md shadow-md text-lg transition duration-200">
                            <i class="fas fa-save mr-2"></i> Guardar Fotos
                        </button>
                        <button id="btn-reset" class="bg-orange-500 hover:bg-orange-600 text-white font-bold px-6 py-3 rounded-md shadow-md text-lg transition duration-200">
                            <i class="fas fa-redo mr-2"></i> Limpiar
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
        const btnSave = document.getElementById('btn-save');
        const btnReset = document.getElementById('btn-reset');
        const cameraContainer = document.getElementById('camera-container');
        const captureControls = document.getElementById('capture-controls');
        const gallerySection = document.getElementById('gallery-section');
        const saveSection = document.getElementById('save-section');
        const photoCountEl = document.getElementById('photo-count');
        const statusMsg = document.getElementById('status-msg');

        let stream = null;

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
                alert("No se pudo acceder a la cámara. Necesitas HTTPS o permisos del navegador.");
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

        // Guardar fotos
        btnSave.addEventListener('click', async () => {
            if (capturedPhotos.length === 0) {
                alert("No has tomado ninguna foto.");
                return;
            }

            btnSave.disabled = true;
            btnSave.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
            statusMsg.textContent = 'Procesando archivos...';
            statusMsg.className = "mt-3 text-sm font-medium text-blue-600";

            const formData = new URLSearchParams();
            formData.append('fotos', JSON.stringify(capturedPhotos));

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });

                const data = await response.json();

                if (data.success) {
                    statusMsg.textContent = '¡Fotos guardadas con éxito!';
                    statusMsg.className = "mt-3 text-sm font-bold text-green-600";

                    setTimeout(() => {
                        window.location.href = 'ver_paciente.php?id=<?php echo $paciente_id; ?>';
                    }, 1200);
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                console.error(error);
                statusMsg.textContent = 'Error: ' + error.message;
                statusMsg.className = "mt-3 text-sm font-bold text-red-600";
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="fas fa-save mr-2"></i> Guardar Fotos';
            }
        });

        // Resetear
        btnReset.addEventListener('click', () => {
            if (!confirm("¿Borrar todas las fotos capturadas?")) return;

            capturedPhotos.length = 0;
            updateGallery();
            gallerySection.style.display = 'none';
            saveSection.style.display = 'none';
            statusMsg.textContent = '';

            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            cameraContainer.classList.add('hidden');
            captureControls.classList.add('hidden');
            btnStartCamera.classList.remove('hidden');
        });
    </script>
</body>
</html>
