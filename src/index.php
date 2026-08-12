<?php
require_once 'db.php';

$error = '';
$exito = '';

// 1. PROCESAR REGISTRO O LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // REGISTRO
    if (isset($_POST['registrar'])) {
        $usuario = trim($_POST['usuario']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        if (!empty($usuario) && !empty($_POST['password'])) {
            $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password) VALUES (?, ?)");
            $stmt->bind_param("ss", $usuario, $password);
            try {
                $stmt->execute();
                $exito = "¡Registro exitoso! Ahora puedes iniciar sesión.";
            } catch (mysqli_sql_exception $e) {
                if ($conn->errno === 1062) {
                    $error = "Este usuario ya existe.";
                } else {
                    $error = "Ocurrió un error al registrar el usuario.";
                }
            }
            $stmt->close();
        }
    }

    // LOGIN
    if (isset($_POST['login'])) {
        $usuario = trim($_POST['usuario']);
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, usuario, password FROM usuarios WHERE usuario = ?");
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($row = $resultado->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['usuario'];
                header("Location: index.php");
                exit();
            } else {
                $error = "Contraseña incorrecta.";
            }
        } else {
            $error = "El usuario no existe.";
        }
        $stmt->close();
    }

    // GUARDAR O ACTUALIZAR TAREA
    if (isset($_SESSION['user_id']) && isset($_POST['guardar_tarea'])) {
        $user_id = $_SESSION['user_id'];
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $titulo = $conn->real_escape_string($_POST['titulo']);
        $descripcion = $conn->real_escape_string($_POST['descripcion']);
        
        $imagen_nombre = null;

        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['imagen']['tmp_name'];
            $fileName = $_FILES['imagen']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($fileExtension, $allowedExtensions)) {
                
                $uploadFileDir = './uploads/';
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }

                if (function_exists('imagecreatetruecolor')) {
                    $newFileName = md5(time() . $fileName) . '.webp';
                    $dest_path = $uploadFileDir . $newFileName;
                    list($width, $height) = getimagesize($fileTmpPath);
                    
                    $max_width = 1200;
                    if ($width > $max_width) {
                        $new_width = $max_width;
                        $new_height = floor($height * ($max_width / $width));
                    } else {
                        $new_width = $width;
                        $new_height = $height;
                    }
                    
                    $image_p = imagecreatetruecolor($new_width, $new_height);
                    
                    switch ($fileExtension) {
                        case 'jpg':
                        case 'jpeg':
                            $image = imagecreatefromjpeg($fileTmpPath);
                            break;
                        case 'png':
                            $image = imagecreatefrompng($fileTmpPath);
                            imagecolortransparent($image_p, imagecolorallocatealpha($image_p, 0, 0, 0, 127));
                            imagealphablending($image_p, false);
                            imagesavealpha($image_p, true);
                            break;
                        case 'webp':
                            $image = imagecreatefromwebp($fileTmpPath);
                            break;
                        default:
                            $image = null;
                    }
                    
                    if ($image) {
                        imagecopyresampled($image_p, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                        imagewebp($image_p, $dest_path, 100); 
                        imagedestroy($image_p);
                        imagedestroy($image);
                        $imagen_nombre = $newFileName;
                    }
                } else {
                    $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                    $dest_path = $uploadFileDir . $newFileName;
                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        $imagen_nombre = $newFileName;
                    }
                }
            }
        }

        if (!empty($titulo)) {
            if ($id > 0) {
                if ($imagen_nombre) {
                    $stmt = $conn->prepare("UPDATE tabla1 SET titulo = ?, descripcion = ?, imagen = ? WHERE id = ? AND usuario_id = ?");
                    $stmt->bind_param("sssii", $titulo, $descripcion, $imagen_nombre, $id, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE tabla1 SET titulo = ?, descripcion = ? WHERE id = ? AND usuario_id = ?");
                    $stmt->bind_param("ssii", $titulo, $descripcion, $id, $user_id);
                }
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO tabla1 (usuario_id, titulo, descripcion, imagen) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $user_id, $titulo, $descripcion, $imagen_nombre);
                $stmt->execute();
                $stmt->close();
            }
        }
        header("Location: index.php");
        exit();
    }
}

// 2. CERRAR SESIÓN
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// 3. ELIMINAR TAREA
if (isset($_SESSION['user_id']) && isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $user_id = $_SESSION['user_id'];
    
    $resImg = $conn->query("SELECT imagen FROM tabla1 WHERE id = $id AND usuario_id = $user_id");
    if($imgRow = $resImg->fetch_assoc()) {
        if(!empty($imgRow['imagen']) && file_exists('./uploads/' . $imgRow['imagen'])) {
            unlink('./uploads/' . $imgRow['imagen']);
        }
    }

    $conn->query("DELETE FROM tabla1 WHERE id = $id AND usuario_id = $user_id");
    header("Location: index.php");
    exit();
}

// Variables para edición
$modo_edicion = false;
$id_editar = '';
$titulo_editar = '';
$descripcion_editar = '';

if (isset($_SESSION['user_id']) && isset($_GET['editar'])) {
    $id_editar = intval($_GET['editar']);
    $user_id = $_SESSION['user_id'];
    $res_ed = $conn->query("SELECT * FROM tabla1 WHERE id = $id_editar AND usuario_id = $user_id");
    if ($res_ed && $res_ed->num_rows > 0) {
        $t_edit = $res_ed->fetch_assoc();
        $modo_edicion = true;
        $titulo_editar = $t_edit['titulo'];
        $descripcion_editar = $t_edit['descripcion'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard LAMP - Animación Wobbly Windows</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        // Aplicar el tema guardado lo antes posible para evitar parpadeo
        if (localStorage.getItem('tema') === 'light') {
            document.documentElement.classList.remove('dark');
        } else {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        @keyframes wobblyOpen {
            0% {
                opacity: 0;
                transform: scale(0.3) skew(18deg, 12deg) rotate(-8deg);
            }
            30% {
                opacity: 1;
                transform: scale(1.18, 0.82) skew(-12deg, -8deg) rotate(5deg);
            }
            50% {
                transform: scale(0.92, 1.1) skew(8deg, 5deg) rotate(-3deg);
            }
            70% {
                transform: scale(1.05, 0.95) skew(-4deg, -2deg) rotate(1.5deg);
            }
            85% {
                transform: scale(0.98, 1.02) skew(2deg, 1deg) rotate(-0.5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1, 1) skew(0deg, 0deg) rotate(0deg);
            }
        }

        @keyframes wobblyClose {
            0% {
                opacity: 1;
                transform: scale(1, 1) skew(0deg, 0deg);
            }
            30% {
                transform: scale(1.1, 0.9) skew(10deg, 5deg);
            }
            100% {
                opacity: 0;
                transform: scale(0.2, 0.2) skew(-20deg, -15deg);
            }
        }

        .animar-wobbly-entrada {
            animation: wobblyOpen 0.65s cubic-bezier(0.25, 1, 0.5, 1) forwards;
            transform-origin: center center;
        }

        .animar-wobbly-salida {
            animation: wobblyClose 0.3s ease-in forwards;
            transform-origin: center center;
        }

        #modalNota {
            opacity: 0;
            transition: opacity 0.25s ease;
        }
    </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 text-slate-900 dark:text-slate-100 min-h-screen py-10 px-4 transition-colors">

    <div class="max-w-4xl mx-auto space-y-8">
        
        <header class="flex flex-col sm:flex-row justify-between items-center bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 gap-4 transition-colors">
            <div>
                <h1 class="text-3xl font-extrabold text-indigo-400 flex items-center gap-3">
                    <i class="fa-solid fa-cubes text-3xl"></i> Dashboard Docker
                </h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">Apache + PHP <?= phpversion() ?> | Sistema de Notas Interactivo</p>
            </div>
            
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="flex items-center gap-4">
                    <button id="btnTema" onclick="alternarTema()" type="button"
                            class="text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg w-9 h-9 flex items-center justify-center transition"
                            title="Cambiar tema">
                        <i id="iconoTema" class="fa-solid fa-moon"></i>
                    </button>
                    <span class="text-sm text-slate-600 dark:text-slate-300">Usuario: <b><?= htmlspecialchars($_SESSION['username']) ?></b></span>
                    <a href="index.php?logout=true" class="bg-rose-600 hover:bg-rose-500 text-white px-4 py-2 rounded-lg text-xs font-semibold transition">
                        <i class="fa-solid fa-right-from-bracket"></i> Salir
                    </a>
                </div>
            <?php else: ?>
                <button id="btnTema" onclick="alternarTema()" type="button"
                        class="text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg w-9 h-9 flex items-center justify-center transition"
                        title="Cambiar tema">
                    <i id="iconoTema" class="fa-solid fa-moon"></i>
                </button>
            <?php endif; ?>
        </header>

        <?php if (!isset($_SESSION['user_id'])): ?>
            <div class="grid md:grid-cols-2 gap-8 max-w-2xl mx-auto">
                
                <?php if ($error): ?>
                    <div class="md:col-span-2 bg-rose-500/10 border border-rose-500 text-rose-400 p-3 rounded-lg text-sm text-center">
                        <?= $error ?>
                    </div>
                <?php endif; ?>
                <?php if ($exito): ?>
                    <div class="md:col-span-2 bg-emerald-500/10 border border-emerald-500 text-emerald-400 p-3 rounded-lg text-sm text-center">
                        <?= $exito ?>
                    </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl transition-colors">
                    <h2 class="text-xl font-bold text-indigo-400 mb-4"><i class="fa-solid fa-right-to-bracket"></i> Iniciar Sesión</h2>
                    <form action="index.php" method="POST" class="space-y-4">
                        <div>
                            <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Usuario</label>
                            <input type="text" name="usuario" required class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:border-indigo-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Contraseña</label>
                            <input type="password" name="password" required class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:border-indigo-500 transition-colors">
                        </div>
                        <button type="submit" name="login" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 rounded-lg text-sm transition">Entrar</button>
                    </form>
                </div>

                <div class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl transition-colors">
                    <h2 class="text-xl font-bold text-emerald-400 mb-4"><i class="fa-solid fa-user-plus"></i> Registrarse</h2>
                    <form action="index.php" method="POST" class="space-y-4">
                        <div>
                            <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Nuevo Usuario</label>
                            <input type="text" name="usuario" required class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:border-emerald-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Contraseña</label>
                            <input type="password" name="password" required class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:border-emerald-500 transition-colors">
                        </div>
                        <button type="submit" name="registrar" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2 rounded-lg text-sm transition">Crear Cuenta</button>
                    </form>
                </div>

            </div>

        <?php else: ?>
            <div class="grid md:grid-cols-3 gap-8">
                
                <div class="md:col-span-1 bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl h-fit transition-colors">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                            <i class="fa-solid <?= $modo_edicion ? 'fa-pen-to-square text-amber-400' : 'fa-plus-circle text-indigo-400' ?>"></i> 
                            <?= $modo_edicion ? 'Editar Nota' : 'Nueva Nota' ?>
                        </h2>
                        <?php if ($modo_edicion): ?>
                            <a href="index.php" class="text-xs text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 underline">Cancelar</a>
                        <?php endif; ?>
                    </div>
                    
                    <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="id" value="<?= $id_editar ?>">

                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Título</label>
                            <input type="text" name="titulo" required placeholder="Título..." 
                                   value="<?= htmlspecialchars($titulo_editar) ?>"
                                   class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:border-indigo-500 transition-colors">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Descripción</label>
                            <textarea name="descripcion" rows="3" placeholder="Detalles..." 
                                      class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:border-indigo-500 transition-colors"><?= htmlspecialchars($descripcion_editar) ?></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Adjuntar Imagen</label>
                            <input type="file" name="imagen" accept="image/*" 
                                   class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-600 dark:text-slate-300 file:mr-4 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500 transition-colors">
                        </div>

                        <button type="submit" name="guardar_tarea" 
                                class="w-full <?= $modo_edicion ? 'bg-amber-600 hover:bg-amber-500 shadow-amber-600/30' : 'bg-indigo-600 hover:bg-indigo-500 shadow-indigo-600/30' ?> text-white font-semibold py-2 rounded-lg text-sm transition shadow-lg">
                            <?= $modo_edicion ? 'Actualizar Nota' : 'Guardar Nota' ?>
                        </button>
                    </form>
                </div>

                <div class="md:col-span-2 space-y-4">
                    <h2 class="text-xl font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-indigo-400"></i> Tus Notas Personales
                    </h2>

                    <?php
                    $user_id = $_SESSION['user_id'];
                    $resultado = $conn->query("SELECT * FROM tabla1 WHERE usuario_id = $user_id ORDER BY fecha_creacion DESC");
                    ?>

                    <?php if ($resultado->num_rows > 0): ?>
                        <div class="space-y-3">
                            <?php while ($tarea = $resultado->fetch_assoc()): ?>
                                <div onclick="abrirModal(
                                        '<?= $tarea['id'] ?>',
                                        '<?= htmlspecialchars(addslashes($tarea['titulo']), ENT_QUOTES) ?>', 
                                        '<?= htmlspecialchars(addslashes($tarea['descripcion']), ENT_QUOTES) ?>', 
                                        '<?= !empty($tarea['imagen']) ? './uploads/' . htmlspecialchars($tarea['imagen'], ENT_QUOTES) : '' ?>', 
                                        '<?= date("d/m/Y H:i", strtotime($tarea['fecha_creacion'])) ?>', 
                                        '<?= !empty($tarea['fecha_modificacion']) ? date("d/m/Y H:i", strtotime($tarea['fecha_modificacion'])) : '' ?>'
                                    )" 
                                    class="bg-white dark:bg-slate-800 p-5 rounded-xl border border-slate-200 dark:border-slate-700 shadow-md flex flex-col sm:flex-row justify-between items-start gap-4 hover:border-indigo-500/60 hover:bg-slate-50 dark:hover:bg-slate-800/80 cursor-pointer transition group">
                                    
                                    <div class="space-y-2 flex-1">
                                        <h3 class="font-bold text-indigo-300 text-lg group-hover:text-indigo-200 transition"><?= htmlspecialchars($tarea['titulo']) ?></h3>
                                        <p class="text-slate-600 dark:text-slate-400 text-sm line-clamp-2"><?= htmlspecialchars($tarea['descripcion']) ?></p>
                                        
                                        <?php if (!empty($tarea['imagen'])): ?>
                                            <div class="pt-1">
                                                <span class="inline-flex items-center gap-1.5 text-indigo-400 text-xs font-medium bg-indigo-950/60 border border-indigo-500/30 px-2.5 py-1 rounded-md">
                                                    <i class="fa-regular fa-image"></i> Contiene imagen adjunta
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400 pt-2 border-t border-slate-200 dark:border-slate-700/50 mt-2">
                                            <span><i class="fa-regular fa-calendar-plus text-emerald-400"></i> Creado: <b><?= date("d/m/Y H:i", strtotime($tarea['fecha_creacion'])) ?></b></span>
                                            
                                            <?php if (!empty($tarea['fecha_modificacion']) && $tarea['fecha_modificacion'] !== $tarea['fecha_creacion']): ?>
                                                <span><i class="fa-regular fa-pen-to-square text-amber-400"></i> Modificado: <b><?= date("d/m/Y H:i", strtotime($tarea['fecha_modificacion'])) ?></b></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2 self-end sm:self-start" onclick="event.stopPropagation();">
                                        <a href="index.php?editar=<?= $tarea['id'] ?>" class="text-slate-500 dark:text-slate-400 hover:text-amber-400 p-2 transition" title="Editar">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <a href="index.php?eliminar=<?= $tarea['id'] ?>" class="text-slate-500 dark:text-slate-400 hover:text-rose-400 p-2 transition" title="Eliminar" onclick="return confirm('¿Seguro que deseas eliminar esta nota?');">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-slate-100 dark:bg-slate-800/50 border border-dashed border-slate-300 dark:border-slate-700 p-8 rounded-xl text-center text-slate-500 dark:text-slate-500 transition-colors">
                            <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                            <p>No tienes notas registradas. ¡Crea una usando el formulario!</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>

    </div>

    <div id="modalNota" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" style="display: none;">
        <div id="modalContenido" class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 relative transition-colors">
            
            <div class="flex justify-between items-start gap-4">
                <h3 id="modalTitulo" class="text-xl font-bold text-indigo-500 dark:text-indigo-300 pr-2"></h3>
                
                <div class="flex items-center gap-1 shrink-0">
                    <a id="modalBtnEditar" href="" class="text-slate-500 dark:text-slate-400 hover:text-amber-400 p-2 transition" title="Editar">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <a id="modalBtnEliminar" href="" onclick="return confirm('¿Seguro que deseas eliminar esta nota?');" class="text-slate-500 dark:text-slate-400 hover:text-rose-400 p-2 transition" title="Eliminar">
                        <i class="fa-solid fa-trash-can"></i>
                    </a>
                    <button onclick="cerrarModal()" class="text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 p-2 transition ml-2" title="Cerrar">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>
            </div>
            
            <div id="modalContenedorImagen" class="hidden">
                <img id="modalImagen" src="" alt="Imagen de nota" class="w-full max-h-72 object-contain rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-900/50">
            </div>

            <p id="modalDescripcion" class="text-slate-700 dark:text-slate-300 text-sm whitespace-pre-wrap leading-relaxed"></p>

            <div class="flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400 pt-3 border-t border-slate-200 dark:border-slate-700">
                <span>Creado: <b id="modalCreado" class="text-slate-800 dark:text-slate-200"></b></span>
                <span id="modalModificadoContenedor" class="hidden">Modificado: <b id="modalModificado" class="text-slate-800 dark:text-slate-200"></b></span>
            </div>
        </div>
    </div>

    <script>
        function abrirModal(id, titulo, descripcion, imagenUrl, creado, modificado) {
            document.getElementById('modalTitulo').innerText = titulo;
            document.getElementById('modalDescripcion').innerText = descripcion;
            
            const imgContainer = document.getElementById('modalContenedorImagen');
            const imgElement = document.getElementById('modalImagen');
            
            if (imagenUrl && imagenUrl !== '') {
                imgElement.src = imagenUrl;
                imgContainer.classList.remove('hidden');
            } else {
                imgContainer.classList.add('hidden');
            }

            document.getElementById('modalCreado').innerText = creado;
            
            const modContainer = document.getElementById('modalModificadoContenedor');
            if (modificado && modificado !== creado) {
                document.getElementById('modalModificado').innerText = modificado;
                modContainer.classList.remove('hidden');
            } else {
                modContainer.classList.add('hidden');
            }

            // Enlaces dinámicos
            document.getElementById('modalBtnEditar').href = 'index.php?editar=' + id;
            document.getElementById('modalBtnEliminar').href = 'index.php?eliminar=' + id;

            // Mostrar el modal y reiniciar la animación
            const modal = document.getElementById('modalNota');
            const contenido = document.getElementById('modalContenido');
            
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            
            contenido.classList.remove('animar-wobbly-salida');
            contenido.classList.remove('animar-wobbly-entrada');
            
            // Forzar reflow para reiniciar la animación CSS si se vuelve a hacer clic
            void contenido.offsetWidth;
            
            contenido.classList.add('animar-wobbly-entrada');
        }

        function cerrarModal() {
            const modal = document.getElementById('modalNota');
            const contenido = document.getElementById('modalContenido');
            
            contenido.classList.remove('animar-wobbly-entrada');
            contenido.classList.add('animar-wobbly-salida');
            modal.style.opacity = '0';

            setTimeout(() => {
                modal.style.display = 'none';
                contenido.classList.remove('animar-wobbly-salida');
            }, 300);
        }

        function alternarTema() {
            const esOscuro = document.documentElement.classList.toggle('dark');
            localStorage.setItem('tema', esOscuro ? 'dark' : 'light');
            actualizarIconoTema();
        }

        function actualizarIconoTema() {
            const icono = document.getElementById('iconoTema');
            if (!icono) return;
            const esOscuro = document.documentElement.classList.contains('dark');
            icono.className = esOscuro ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
        }

        actualizarIconoTema();

        window.onclick = function(event) {
            let modal = document.getElementById('modalNota');
            if (event.target === modal) {
                cerrarModal();
            }
        }
    </script>
</body>
</html>