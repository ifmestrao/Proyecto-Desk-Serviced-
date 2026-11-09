<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_check()) {
        die("CSRF token inválido");
    }

    $user_id = $_SESSION['user_id'];
    $tenant_id = $_SESSION['tenant_id'];

    // Handle gallery upload
    if (isset($_POST['upload_gallery']) && isset($_FILES['gallery_image'])) {
        try {
            $pdo->beginTransaction();
            
            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'gallery' . DIRECTORY_SEPARATOR;
            
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0777, true)) {
                    throw new Exception("Error al crear el directorio de galería");
                }
            }
            
            if (!is_writable($uploadDir)) {
                @chmod($uploadDir, 0777);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            
            foreach ($_FILES['gallery_image']['name'] as $key => $originalName) {
                if ($_FILES['gallery_image']['error'][$key] !== UPLOAD_ERR_OK) {
                    continue;
                }
                
                $tmpPath = $_FILES['gallery_image']['tmp_name'][$key];
                $mime = $finfo->file($tmpPath);
                
                if (!in_array($mime, $allowedTypes)) {
                    continue;
                }
                
                $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                $fileName = 'gallery_' . $user_id . '_' . time() . '_' . uniqid() . '.' . $ext;
                $dest = $uploadDir . $fileName;
                $relativeDest = 'uploads/gallery/' . $fileName;
                
                if (move_uploaded_file($tmpPath, $dest)) {
                    $stmt = $pdo->prepare("INSERT INTO user_gallery (tenant_id, user_id, file_name, file_path, mime_type) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$tenant_id, $user_id, $originalName, $relativeDest, $mime]);
                }
            }
            
            $pdo->commit();
            header("Location: profile.php?success=Imagen(es) subidas correctamente");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: profile.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // Handle gallery delete
    if (isset($_POST['delete_gallery']) && isset($_POST['gallery_id'])) {
        try {
            $galleryId = (int)$_POST['gallery_id'];
            $stmt = $pdo->prepare("SELECT * FROM user_gallery WHERE id = ? AND user_id = ? AND tenant_id = ?");
            $stmt->execute([$galleryId, $user_id, $tenant_id]);
            $image = $stmt->fetch();
            
            if ($image && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $image['file_path'])) {
                @unlink(__DIR__ . DIRECTORY_SEPARATOR . $image['file_path']);
            }
            
            $deleteStmt = $pdo->prepare("DELETE FROM user_gallery WHERE id = ? AND user_id = ? AND tenant_id = ?");
            $deleteStmt->execute([$galleryId, $user_id, $tenant_id]);
            
            header("Location: profile.php?success=Imagen eliminada correctamente");
            exit;
        } catch (Exception $e) {
            header("Location: profile.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // Handle profile update
    if (isset($_POST['update_profile']) || isset($_FILES['avatar'])) {
        $name = $_POST['name'] ?? null;
        $email = $_POST['email'] ?? null;
        $phone = $_POST['phone'] ?? null;
        $area = $_POST['area'] ?? null;
        $position = $_POST['position'] ?? null;

        try {
            $pdo->beginTransaction();

            // Handle Avatar Upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['name'] !== '') {
                if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    $error_msg = "Error en la subida: ";
                    switch ($_FILES['avatar']['error']) {
                        case UPLOAD_ERR_INI_SIZE: $error_msg .= "El archivo excede el tamaño máximo permitido por el servidor"; break;
                        case UPLOAD_ERR_FORM_SIZE: $error_msg .= "El archivo excede el tamaño máximo permitido por el formulario"; break;
                        case UPLOAD_ERR_PARTIAL: $error_msg .= "El archivo se subió parcialmente"; break;
                        case UPLOAD_ERR_NO_FILE: $error_msg .= "No se seleccionó ningún archivo"; break;
                        default: $error_msg .= "Error desconocido"; break;
                    }
                    throw new Exception($error_msg);
                }

                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
                
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new Exception("Error al crear el directorio de avatares");
                    }
                }

                if (!is_writable($uploadDir)) {
                    @chmod($uploadDir, 0777);
                    if (!is_writable($uploadDir)) {
                        throw new Exception("Error: El directorio de destino no tiene permisos de escritura");
                    }
                }
                
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($_FILES['avatar']['tmp_name']);
                
                if (!in_array($mimeType, $allowedTypes)) {
                    throw new Exception("Tipo de archivo no permitido. Solo se aceptan imágenes");
                }

                $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                $fileName = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $fileName;
                $relativeDest = 'uploads/avatars/' . $fileName;

                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                    $stOld = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                    $stOld->execute([$user_id]);
                    $oldAvatar = $stOld->fetchColumn();
                    if ($oldAvatar && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $oldAvatar)) {
                        @unlink(__DIR__ . DIRECTORY_SEPARATOR . $oldAvatar);
                    }

                    $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                    $stmt->execute([$relativeDest, $user_id]);
                } else {
                    throw new Exception("Error al mover el archivo al directorio de destino");
                }
            }

            // Handle text fields
            if ($name && $email) {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, area = ?, position = ? WHERE id = ?");
                $stmt->execute([$name, $email, $phone, $area, $position, $user_id]);
                
                // Update session
                $_SESSION['name'] = $name;
            }

            $pdo->commit();
            header("Location: profile.php?success=Perfil actualizado correctamente");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: profile.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }
}
?>
