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

// Obtener ID del paciente
$paciente_id = $_GET['id'] ?? 0;

if (!$paciente_id) {
    header('Location: index.php');
    exit;
}

// Obtener paciente
$stmt = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
$stmt->execute([$paciente_id]);
$paciente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$paciente) {
    header('Location: index.php');
    exit;
}

// Obtener fotos
$stmtFotos = $pdo->prepare("SELECT * FROM fotos_historias WHERE paciente_id = ? ORDER BY fecha_subida DESC");
$stmtFotos->execute([$paciente_id]);
$fotos = $stmtFotos->fetchAll(PDO::FETCH_ASSOC);

// Procesar acciones POST (editar, eliminar foto, eliminar paciente)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'edit_patient') {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $medico = trim($_POST['medico'] ?? '');

            if (empty($nombre) || empty($apellido) || empty($medico)) {
                throw new Exception("Faltan datos obligatorios.");
            }

            $stmt = $pdo->prepare("UPDATE pacientes SET nombre = ?, apellido = ?, medico_tratante = ? WHERE id = ?");
            $stmt->execute([$nombre, $apellido, $medico, $paciente_id]);

            echo json_encode(['success' => true, 'message' => 'Datos actualizados correctamente.']);
            exit;
        }

        if ($action === 'delete_photo') {
            $photo_id = $_POST['photo_id'] ?? 0;

            if (empty($photo_id)) {
                throw new Exception("ID de foto no válido.");
            }

            $stmt = $pdo->prepare("SELECT ruta_foto FROM fotos_historias WHERE id = ?");
            $stmt->execute([$photo_id]);
            $photo = $stmt->fetch();

            if (!$photo) {
                throw new Exception("Foto no encontrada.");
            }

            $filePath = __DIR__ . '/' . $photo['ruta_foto'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $stmt = $pdo->prepare("DELETE FROM fotos_historias WHERE id = ?");
            $stmt->execute([$photo_id]);

            echo json_encode(['success' => true, 'message' => 'Foto eliminada correctamente.']);
            exit;
        }

        if ($action === 'delete_patient') {
            $folderNameRaw = "{$paciente['nombre']} - {$paciente['cedula']} - {$paciente['medico_tratante']}";
            $folderName = preg_replace('/[^a-zA-Z0-9\-\s]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $folderNameRaw));
            $targetDir = __DIR__ . '/historias_guardadas/' . $folderName;

            if (is_dir($targetDir)) {
                $files = glob($targetDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($targetDir);
            }

            $stmt = $pdo->prepare("DELETE FROM pacientes WHERE id = ?");
            $stmt->execute([$paciente_id]);

            echo json_encode(['success' => true, 'message' => 'Paciente eliminado correctamente.']);
            exit;
        }

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
    <title><?php echo htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']); ?> - Historia Médica</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .photo-overlay {
            opacity: 0;
            transition: opacity 0.2s;
        }
        .photo-card:hover .photo-overlay {
            opacity: 1;
        }
        .lightbox {
            display: none;
            position: fixed;
            z-index: 100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.95);
            cursor: zoom-out;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased pb-20 min-h-screen">

    <!-- Lightbox para ver foto completa -->
    <div id="lightbox" class="lightbox" onclick="closeLightbox()">
        <button class="absolute top-4 right-4 text-white text-3xl hover:text-gray-300 z-10">
            <i class="fas fa-times"></i>
        </button>
        <img id="lightbox-img" src="" class="max-w-[95vw] max-h-[95vh] object-contain rounded-lg shadow-2xl">
    </div>

    <!-- Modal de confirmación -->
    <div id="confirmModal" class="fixed inset-0 z-50 hidden bg-black/60 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
            <div id="confirmContent"></div>
        </div>
    </div>

    <!-- Modal de edición inline -->
    <div id="editModal" class="fixed inset-0 z-50 hidden bg-black/60 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6">
            <div id="editContent"></div>
        </div>
    </div>

    <div class="max-w-5xl mx-auto p-4 sm:p-6 mt-4">

        <!-- Volver -->
        <a href="index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-4 transition">
            <i class="fas fa-arrow-left mr-2"></i> Volver al listado
        </a>

        <!-- Header del paciente -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="bg-blue-600 p-5 text-white">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold">
                            <i class="fas fa-user mr-2"></i>
                            <?php echo htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']); ?>
                        </h1>
                        <p class="text-sm opacity-90 mt-1">Cédula: <?php echo htmlspecialchars($paciente['cedula']); ?></p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openEditModal()" 
                                class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-md transition text-sm font-medium">
                            <i class="fas fa-edit mr-1"></i> Editar
                        </button>
                        <a href="agregar_fotos.php?id=<?php echo $paciente_id; ?>" 
                           class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-md transition text-sm font-medium">
                            <i class="fas fa-plus mr-1"></i> Agregar Fotos
                        </a>
                        <button onclick="confirmDeletePatient()" 
                                class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-md transition text-sm font-medium">
                            <i class="fas fa-trash mr-1"></i> Eliminar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info del paciente -->
            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-50 rounded-lg p-3">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Nombre</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($paciente['nombre']); ?></p>
                </div>
                <div class="bg-slate-50 rounded-lg p-3">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Apellido</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($paciente['apellido']); ?></p>
                </div>
                <div class="bg-slate-50 rounded-lg p-3">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Médico Tratante</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($paciente['medico_tratante']); ?></p>
                </div>
                <div class="bg-slate-50 rounded-lg p-3">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Fecha de Registro</p>
                    <p class="font-semibold text-gray-800"><?php echo date('d/m/Y H:i', strtotime($paciente['fecha_registro'])); ?></p>
                </div>
            </div>
        </div>

        <!-- Galería de documentos -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-5 border-b">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-images text-blue-600 mr-2"></i>
                        Documentos
                        <span class="bg-blue-100 text-blue-700 text-sm font-medium px-2 py-0.5 rounded-full ml-2">
                            <?php echo count($fotos); ?>
                        </span>
                    </h2>
                </div>
            </div>

            <div class="p-5">
                <?php if (empty($fotos)): ?>
                    <div class="text-center py-12 text-gray-400">
                        <i class="fas fa-folder-open text-5xl mb-3"></i>
                        <p class="text-lg">No hay documentos capturados</p>
                        <a href="agregar_fotos.php?id=<?php echo $paciente_id; ?>" 
                           class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-md transition">
                            <i class="fas fa-camera mr-1"></i> Capturar primer documento
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                        <?php foreach ($fotos as $index => $foto): ?>
                            <div class="photo-card relative rounded-lg overflow-hidden border border-gray-200 shadow-sm aspect-[3/4] group">
                                <img src="<?php echo htmlspecialchars($foto['ruta_foto']); ?>" 
                                     class="w-full h-full object-cover cursor-pointer"
                                     alt="Documento <?php echo $index + 1; ?>"
                                     onclick="openLightbox('<?php echo htmlspecialchars($foto['ruta_foto']); ?>')">
                                
                                <!-- Overlay con acciones -->
                                <div class="photo-overlay absolute inset-0 bg-black/40 flex flex-col items-center justify-center gap-2">
                                    <button onclick="openLightbox('<?php echo htmlspecialchars($foto['ruta_foto']); ?>')" 
                                            class="bg-white/90 hover:bg-white text-gray-800 rounded-full w-9 h-9 flex items-center justify-center shadow transition">
                                        <i class="fas fa-expand text-sm"></i>
                                    </button>
                                    <button onclick="confirmDeletePhoto(<?php echo $foto['id']; ?>)" 
                                            class="bg-red-500/90 hover:bg-red-500 text-white rounded-full w-9 h-9 flex items-center justify-center shadow transition">
                                        <i class="fas fa-trash text-sm"></i>
                                    </button>
                                </div>

                                <!-- Número de orden -->
                                <span class="absolute bottom-1 left-1 bg-black/60 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center">
                                    <?php echo $index + 1; ?>
                                </span>

                                <!-- Fecha -->
                                <span class="absolute bottom-1 right-1 bg-black/60 text-white text-[10px] rounded px-1.5 py-0.5">
                                    <?php echo date('d/m', strtotime($foto['fecha_subida'])); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ==========================================
        // LIGHTBOX
        // ==========================================
        function openLightbox(src) {
            document.getElementById('lightbox-img').src = src;
            document.getElementById('lightbox').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            document.getElementById('lightbox').style.display = 'none';
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeLightbox();
                closeEditModal();
            }
        });

        // ==========================================
        // MODAL DE EDICIÓN
        // ==========================================
        function openEditModal() {
            const editContent = document.getElementById('editContent');
            editContent.innerHTML = `
                <h2 class="text-xl font-bold mb-4"><i class="fas fa-edit mr-2"></i> Editar Datos</h2>
                <form id="editForm" onsubmit="saveEdit(event)">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                            <input type="text" id="editNombre" value="<?php echo htmlspecialchars($paciente['nombre']); ?>" 
                                   class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Apellido</label>
                            <input type="text" id="editApellido" value="<?php echo htmlspecialchars($paciente['apellido']); ?>" 
                                   class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cédula</label>
                            <input type="text" value="<?php echo htmlspecialchars($paciente['cedula']); ?>" 
                                   class="w-full border border-gray-300 rounded-md p-2.5 bg-gray-100" disabled>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Médico Tratante</label>
                            <input type="text" id="editMedico" value="<?php echo htmlspecialchars($paciente['medico_tratante']); ?>" 
                                   class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-md transition font-medium">
                            <i class="fas fa-save mr-1"></i> Guardar Cambios
                        </button>
                        <button type="button" onclick="closeEditModal()" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2.5 rounded-md transition font-medium">
                            <i class="fas fa-times mr-1"></i> Cancelar
                        </button>
                    </div>
                </form>
            `;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function saveEdit(event) {
            event.preventDefault();

            const nombre = document.getElementById('editNombre').value;
            const apellido = document.getElementById('editApellido').value;
            const medico = document.getElementById('editMedico').value;

            if (!nombre || !apellido || !medico) {
                alert("Completa todos los campos.");
                return;
            }

            const formData = new URLSearchParams();
            formData.append('action', 'edit_patient');
            formData.append('nombre', nombre);
            formData.append('apellido', apellido);
            formData.append('medico', medico);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert(data.message || "Error al guardar.");
                }
            })
            .catch(err => {
                console.error(err);
                alert("Error al guardar los cambios.");
            });
        }

        // ==========================================
        // ELIMINAR FOTO
        // ==========================================
        function confirmDeletePhoto(photoId) {
            document.getElementById('confirmContent').innerHTML = `
                <h2 class="text-xl font-bold mb-3"><i class="fas fa-exclamation-triangle mr-2 text-red-600"></i> Eliminar Foto</h2>
                <p class="mb-6 text-gray-600">¿Estás seguro? Esta acción no se puede deshacer.</p>
                <div class="flex gap-2">
                    <button onclick="deletePhoto(${photoId})" 
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-md transition font-medium">
                        <i class="fas fa-trash mr-1"></i> Eliminar
                    </button>
                    <button onclick="closeConfirmModal()" 
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2.5 rounded-md transition font-medium">
                        Cancelar
                    </button>
                </div>
            `;
            document.getElementById('confirmModal').classList.remove('hidden');
        }

        function deletePhoto(photoId) {
            const formData = new URLSearchParams();
            formData.append('action', 'delete_photo');
            formData.append('photo_id', photoId);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || "Error al eliminar.");
                }
            })
            .catch(err => {
                console.error(err);
                alert("Error al eliminar la foto.");
            });
        }

        // ==========================================
        // ELIMINAR PACIENTE
        // ==========================================
        function confirmDeletePatient() {
            document.getElementById('confirmContent').innerHTML = `
                <h2 class="text-xl font-bold mb-3"><i class="fas fa-exclamation-triangle mr-2 text-red-600"></i> Eliminar Paciente</h2>
                <p class="mb-2 text-gray-600">Vas a eliminar a <strong><?php echo htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']); ?></strong> y TODOS sus documentos.</p>
                <p class="mb-6 text-red-600 font-medium">Esta acción no se puede deshacer.</p>
                <div class="flex gap-2">
                    <button onclick="deletePatient()" 
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-md transition font-medium">
                        <i class="fas fa-trash mr-1"></i> Sí, Eliminar
                    </button>
                    <button onclick="closeConfirmModal()" 
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2.5 rounded-md transition font-medium">
                        Cancelar
                    </button>
                </div>
            `;
            document.getElementById('confirmModal').classList.remove('hidden');
        }

        function deletePatient() {
            const formData = new URLSearchParams();
            formData.append('action', 'delete_patient');

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'index.php?deleted=1';
                } else {
                    alert(data.message || "Error al eliminar.");
                }
            })
            .catch(err => {
                console.error(err);
                alert("Error al eliminar el paciente.");
            });
        }

        // ==========================================
        // CERRAR MODALES
        // ==========================================
        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) closeConfirmModal();
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
    </script>
</body>
</html>
