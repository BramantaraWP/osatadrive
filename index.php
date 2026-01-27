<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// KONFIGURASI DATABASE SQLite
// ============================================
define('DB_FILE', 'osis_cloud.db');
define('BOT_TOKEN', '8401425763:AAGzfWOOETNcocI7JCj9zQxBhZZ2fVaworI');
define('CHAT_ID', '-1003838508884');
define('API_URL', 'https://api.telegram.org/bot');
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('UPLOAD_TIMEOUT', 300); // 5 menit

// Base URL untuk link sharing
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

// ============================================
// INISIALISASI DATABASE
// ============================================
try {
    $db = new PDO("sqlite:" . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buat tabel jika belum ada
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_file_id TEXT UNIQUE NOT NULL,
            original_name TEXT NOT NULL,
            display_name TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            mime_type TEXT,
            caption TEXT,
            uploaded_by INTEGER,
            upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS file_renames (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            old_name TEXT NOT NULL,
            new_name TEXT NOT NULL,
            renamed_by INTEGER,
            rename_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (file_id) REFERENCES files(id),
            FOREIGN KEY (renamed_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS file_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_token TEXT UNIQUE NOT NULL,
            file_id INTEGER NOT NULL,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            FOREIGN KEY (file_id) REFERENCES files(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            session_token TEXT UNIQUE NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");
    
    // Cek apakah ada user default
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch()['count'];
    
    if ($count == 0) {
        // Tambah user default
        $users = [
            ['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Administrator'],
            ['osis', password_hash('osis2024', PASSWORD_DEFAULT), 'OSIS Member'],
            ['user', password_hash('user123', PASSWORD_DEFAULT), 'Regular User']
        ];
        
        foreach ($users as $user) {
            $stmt = $db->prepare("INSERT INTO users (username, password, full_name) VALUES (?, ?, ?)");
            $stmt->execute($user);
        }
    }
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// ============================================
// FUNGSI UTILITAS DATABASE
// ============================================
function getUserId($username) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    return $user ? $user['id'] : null;
}

function getUserById($id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function saveFileToDB($telegram_file_id, $filename, $size, $mime_type, $caption, $uploaded_by) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO files 
            (telegram_file_id, original_name, display_name, file_size, mime_type, caption, uploaded_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $telegram_file_id,
            $filename,
            $filename,
            $size,
            $mime_type,
            $caption,
            $uploaded_by
        ]);
        
        return $db->lastInsertId();
    } catch (PDOException $e) {
        error_log("Save File Error: " . $e->getMessage());
        return false;
    }
}

function getFileByTelegramId($telegram_file_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT f.*, u.username as uploaded_by_name 
        FROM files f 
        LEFT JOIN users u ON f.uploaded_by = u.id 
        WHERE f.telegram_file_id = ?
    ");
    $stmt->execute([$telegram_file_id]);
    return $stmt->fetch();
}

function getAllFiles($limit = 100) {
    global $db;
    $stmt = $db->prepare("
        SELECT f.*, u.username as uploaded_by_name 
        FROM files f 
        LEFT JOIN users u ON f.uploaded_by = u.id 
        ORDER BY f.upload_date DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getFilesByUser($user_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT f.*, u.username as uploaded_by_name 
        FROM files f 
        LEFT JOIN users u ON f.uploaded_by = u.id 
        WHERE f.uploaded_by = ? 
        ORDER BY f.upload_date DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function updateFileName($file_id, $new_name, $user_id) {
    global $db;
    
    try {
        // Dapatkan nama lama
        $stmt = $db->prepare("SELECT display_name FROM files WHERE id = ?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch();
        
        if (!$file) return false;
        
        // Update nama file
        $stmt = $db->prepare("UPDATE files SET display_name = ? WHERE id = ?");
        $stmt->execute([$new_name, $file_id]);
        
        // Catat perubahan di history
        $stmt = $db->prepare("
            INSERT INTO file_renames (file_id, old_name, new_name, renamed_by) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$file_id, $file['display_name'], $new_name, $user_id]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Rename Error: " . $e->getMessage());
        return false;
    }
}

function createShareToken($file_id, $user_id, $expiry_hours = 24) {
    global $db;
    
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', time() + ($expiry_hours * 3600));
    
    try {
        $stmt = $db->prepare("
            INSERT INTO file_shares (share_token, file_id, created_by, expires_at) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$token, $file_id, $user_id, $expires_at]);
        
        return $token;
    } catch (PDOException $e) {
        error_log("Share Token Error: " . $e->getMessage());
        return false;
    }
}

function getShareInfo($token) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT fs.*, f.telegram_file_id, f.display_name, f.original_name 
        FROM file_shares fs 
        JOIN files f ON fs.file_id = f.id 
        WHERE fs.share_token = ? AND (fs.expires_at IS NULL OR fs.expires_at > datetime('now'))
    ");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

function formatBytes($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}

function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'jpg' => 'fa-image', 'jpeg' => 'fa-image', 'png' => 'fa-image', 
        'gif' => 'fa-image', 'webp' => 'fa-image', 'bmp' => 'fa-image',
        'mp4' => 'fa-video', 'avi' => 'fa-video', 'mov' => 'fa-video', 
        'mkv' => 'fa-video', 'flv' => 'fa-video', 'wmv' => 'fa-video',
        'mp3' => 'fa-music', 'wav' => 'fa-music', 'ogg' => 'fa-music', 
        'm4a' => 'fa-music', 'flac' => 'fa-music',
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive', 
        '7z' => 'fa-file-archive',
        'txt' => 'fa-file-alt'
    ];
    return isset($icons[$ext]) ? $icons[$ext] : 'fa-file';
}

function getFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $videos = ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv'];
    $audios = ['mp3', 'wav', 'ogg', 'm4a', 'flac'];
    
    if (in_array($ext, $images)) return 'image';
    if (in_array($ext, $videos)) return 'video';
    if (in_array($ext, $audios)) return 'audio';
    if ($ext == 'pdf') return 'pdf';
    return 'other';
}

// ============================================
// TELEGRAM API FUNCTIONS
// ============================================
function telegramRequest($method, $params = []) {
    $url = API_URL . BOT_TOKEN . '/' . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POST, true);
        
        $hasFile = false;
        foreach ($params as $param) {
            if ($param instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }
        
        if ($hasFile) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
    }
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return ['ok' => false, 'error' => "CURL Error: $error"];
    }
    
    $data = json_decode($response, true);
    return $data;
}

function uploadToTelegram($file_path, $filename, $caption = '') {
    if (!file_exists($file_path)) {
        return ['ok' => false, 'error' => 'File not found'];
    }
    
    $fileSize = filesize($file_path);
    if ($fileSize > MAX_FILE_SIZE) {
        return ['ok' => false, 'error' => 'File too large (max 2GB)'];
    }
    
    // Upload langsung (untuk file < 50MB)
    $cfile = new CURLFile($file_path, mime_content_type($file_path), $filename);
    
    $params = [
        'chat_id' => CHAT_ID,
        'document' => $cfile,
        'caption' => substr($caption, 0, 1024)
    ];
    
    $result = telegramRequest('sendDocument', $params);
    
    if ($result['ok'] && isset($result['result']['document'])) {
        return [
            'ok' => true,
            'file_id' => $result['result']['document']['file_id'],
            'file_name' => $result['result']['document']['file_name'] ?? $filename,
            'file_size' => $result['result']['document']['file_size'] ?? $fileSize,
            'mime_type' => $result['result']['document']['mime_type'] ?? 'application/octet-stream'
        ];
    }
    
    return $result;
}

function getFileUrl($file_id) {
    $result = telegramRequest('getFile', ['file_id' => $file_id]);
    
    if ($result['ok']) {
        $file_path = $result['result']['file_path'];
        return "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;
    }
    
    return false;
}

// ============================================
// SISTEM LOGIN DENGAN DATABASE
// ============================================
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['loggedin'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['login_time'] = time();
        
        // Simpan session di database
        $session_token = bin2hex(random_bytes(32));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $db->prepare("
            INSERT INTO sessions (user_id, session_token, ip_address, user_agent) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $session_token, $ip, $user_agent]);
        
        $_SESSION['session_token'] = $session_token;
        
        header("Location: " . $base_url);
        exit;
    } else {
        $login_error = "Username atau password salah!";
    }
}

if (isset($_GET['logout'])) {
    if (isset($_SESSION['session_token'])) {
        $stmt = $db->prepare("DELETE FROM sessions WHERE session_token = ?");
        $stmt->execute([$_SESSION['session_token']]);
    }
    session_destroy();
    header("Location: " . $base_url);
    exit;
}

// Cek session dari database
if (isset($_SESSION['session_token'])) {
    $stmt = $db->prepare("
        SELECT s.*, u.username, u.full_name 
        FROM sessions s 
        JOIN users u ON s.user_id = u.id 
        WHERE s.session_token = ? AND s.last_activity > datetime('now', '-24 hours')
    ");
    $stmt->execute([$_SESSION['session_token']]);
    $session = $stmt->fetch();
    
    if ($session) {
        // Update last activity
        $stmt = $db->prepare("UPDATE sessions SET last_activity = datetime('now') WHERE id = ?");
        $stmt->execute([$session['id']]);
        
        $_SESSION['loggedin'] = true;
        $_SESSION['user_id'] = $session['user_id'];
        $_SESSION['username'] = $session['username'];
        $_SESSION['full_name'] = $session['full_name'];
    } else {
        // Session expired
        unset($_SESSION['loggedin']);
        unset($_SESSION['session_token']);
    }
}

// ============================================
// HANDLE UPLOAD FILE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload']) && isset($_SESSION['loggedin'])) {
    if (isset($_FILES['fileupload']) && $_FILES['fileupload']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['fileupload'];
        $caption = $_POST['caption'] ?? '';
        
        if ($file['size'] > MAX_FILE_SIZE) {
            $upload_error = "File terlalu besar (maksimal 2GB)";
        } else {
            $result = uploadToTelegram($file['tmp_name'], $file['name'], $caption);
            
            if ($result['ok']) {
                // Simpan ke database
                $file_id = saveFileToDB(
                    $result['file_id'],
                    $result['file_name'],
                    $result['file_size'],
                    $result['mime_type'],
                    $caption,
                    $_SESSION['user_id']
                );
                
                if ($file_id) {
                    $upload_success = "File '" . htmlspecialchars($result['file_name']) . "' berhasil diupload!";
                } else {
                    $upload_error = "Gagal menyimpan data file ke database";
                }
            } else {
                $upload_error = "Gagal mengupload file ke Telegram: " . ($result['error'] ?? 'Unknown error');
            }
        }
    } elseif (isset($_FILES['fileupload']['error']) && $_FILES['fileupload']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File terlalu besar (php.ini limit)',
            UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (form limit)',
            UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary folder tidak ada',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis ke disk',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP'
        ];
        $upload_error = $upload_errors[$_FILES['fileupload']['error']] ?? 'Upload error ' . $_FILES['fileupload']['error'];
    }
}

// ============================================
// HANDLE DOWNLOAD FILE
// ============================================
if (isset($_GET['download']) && isset($_SESSION['loggedin'])) {
    $file_id = $_GET['file_id'] ?? '';
    
    $file = getFileByTelegramId($file_id);
    if ($file) {
        $file_url = getFileUrl($file_id);
        
        if ($file_url) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['display_name']) . '"');
            header('Content-Transfer-Encoding: binary');
            
            $ch = curl_init($file_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $file_content = curl_exec($ch);
            curl_close($ch);
            
            echo $file_content;
            exit;
        }
    }
    $download_error = "File tidak ditemukan";
}

// ============================================
// HANDLE RENAME FILE
// ============================================
if (isset($_POST['rename']) && isset($_SESSION['loggedin'])) {
    $file_id = $_POST['file_id'] ?? '';
    $new_name = $_POST['new_name'] ?? '';
    
    if (!empty($file_id) && !empty($new_name)) {
        // Cari file di database
        $stmt = $db->prepare("SELECT id FROM files WHERE telegram_file_id = ?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch();
        
        if ($file) {
            if (updateFileName($file['id'], $new_name, $_SESSION['user_id'])) {
                $rename_success = "File berhasil diubah menjadi '" . htmlspecialchars($new_name) . "'";
            } else {
                $rename_error = "Gagal mengubah nama file";
            }
        }
    }
}

// ============================================
// HANDLE SHARE FILE
// ============================================
if (isset($_POST['share']) && isset($_SESSION['loggedin'])) {
    $file_id = $_POST['file_id'] ?? '';
    
    if (!empty($file_id)) {
        // Cari file di database
        $stmt = $db->prepare("SELECT id FROM files WHERE telegram_file_id = ?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch();
        
        if ($file) {
            $token = createShareToken($file['id'], $_SESSION['user_id']);
            if ($token) {
                $share_url = $base_url . "?share=" . $token;
                $share_success = "Link sharing berhasil dibuat!";
                $share_link = $share_url;
            } else {
                $share_error = "Gagal membuat link sharing";
            }
        }
    }
}

// ============================================
// HANDLE SHARED LINK ACCESS
// ============================================
if (isset($_GET['share'])) {
    $token = $_GET['share'] ?? '';
    
    $share_info = getShareInfo($token);
    if ($share_info) {
        $file_url = getFileUrl($share_info['telegram_file_id']);
        
        if ($file_url) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($share_info['display_name']) . '"');
            header('Content-Transfer-Encoding: binary');
            
            $ch = curl_init($file_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $file_content = curl_exec($ch);
            curl_close($ch);
            
            echo $file_content;
            exit;
        }
    } else {
        $share_error = "Link sharing tidak valid atau sudah kadaluarsa";
    }
}

// ============================================
// HANDLE DELETE FILE
// ============================================
if (isset($_GET['delete']) && isset($_SESSION['loggedin'])) {
    $file_id = $_GET['file_id'] ?? '';
    
    if (!empty($file_id)) {
        // Hapus dari database (soft delete - hanya hapus dari table files)
        $stmt = $db->prepare("DELETE FROM files WHERE telegram_file_id = ?");
        if ($stmt->execute([$file_id])) {
            $delete_success = "File berhasil dihapus";
        } else {
            $delete_error = "Gagal menghapus file";
        }
    }
}

// ============================================
// HANDLE SYNC FROM TELEGRAM
// ============================================
if (isset($_GET['sync']) && isset($_SESSION['loggedin'])) {
    // Ambil file dari Telegram (getUpdates)
    $result = telegramRequest('getUpdates', ['offset' => -100, 'limit' => 100]);
    
    if ($result['ok']) {
        $synced = 0;
        foreach ($result['result'] as $update) {
            if (isset($update['channel_post']['document'])) {
                $post = $update['channel_post'];
                $doc = $post['document'];
                
                $telegram_file_id = $doc['file_id'];
                
                // Cek apakah sudah ada di database
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM files WHERE telegram_file_id = ?");
                $stmt->execute([$telegram_file_id]);
                $exists = $stmt->fetch()['count'] > 0;
                
                if (!$exists) {
                    // Simpan ke database
                    saveFileToDB(
                        $telegram_file_id,
                        $doc['file_name'] ?? 'file_' . substr($telegram_file_id, 0, 10),
                        $doc['file_size'] ?? 0,
                        $doc['mime_type'] ?? 'application/octet-stream',
                        $post['caption'] ?? '',
                        $_SESSION['user_id']
                    );
                    $synced++;
                }
            }
        }
        
        if ($synced > 0) {
            $sync_success = "Berhasil sync $synced file dari Telegram";
        } else {
            $sync_info = "Tidak ada file baru dari Telegram";
        }
    } else {
        $sync_error = "Gagal sync dari Telegram";
    }
}

// ============================================
// LOAD FILES DARI DATABASE
// ============================================
$all_files = [];
if (isset($_SESSION['loggedin'])) {
    $all_files = getAllFiles(100);
}

// Hitung statistik
$file_stats = [];
if (isset($_SESSION['loggedin'])) {
    $stmt = $db->query("SELECT COUNT(*) as total_files FROM files");
    $file_stats['total_files'] = $stmt->fetch()['total_files'];
    
    $stmt = $db->query("SELECT SUM(file_size) as total_size FROM files");
    $file_stats['total_size'] = $stmt->fetch()['total_size'] ?? 0;
    
    $stmt = $db->prepare("SELECT COUNT(*) as user_files FROM files WHERE uploaded_by = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $file_stats['user_files'] = $stmt->fetch()['user_files'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSIS Cloud Storage</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #34495e;
            --accent: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Login Page */
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-logo i {
            font-size: 4rem;
            color: var(--accent);
            margin-bottom: 15px;
        }
        
        /* Main App */
        .app-container {
            background: white;
            border-radius: 20px;
            margin: 20px auto;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 1400px;
        }
        
        /* Header */
        .app-header {
            background: var(--primary);
            color: white;
            padding: 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stats-icon {
            font-size: 2rem;
            color: var(--accent);
            margin-bottom: 10px;
        }
        
        /* File Cards */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .file-card {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            transition: all 0.3s;
            background: white;
        }
        
        .file-card:hover {
            border-color: var(--accent);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.2);
            transform: translateY(-3px);
        }
        
        .file-icon {
            font-size: 2rem;
            color: var(--accent);
            margin-right: 15px;
        }
        
        .file-name {
            font-weight: 600;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .file-meta {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 3px;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn-action {
            flex: 1;
            font-size: 0.85rem;
        }
        
        /* Upload Zone */
        .upload-zone {
            border: 3px dashed #ddd;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .upload-zone:hover {
            border-color: var(--accent);
            background: #e3f2fd;
        }
        
        /* Alerts */
        .alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 10px;
            animation: slideIn 0.3s;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* File Type Badges */
        .badge-type {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .badge-image { background: #e3f2fd; color: #1976d2; }
        .badge-video { background: #fce4ec; color: #c2185b; }
        .badge-audio { background: #f3e5f5; color: #7b1fa2; }
        .badge-pdf { background: #ffebee; color: #d32f2f; }
        .badge-other { background: #f5f5f5; color: #616161; }
        
        /* File ID Display */
        .file-id {
            font-family: monospace;
            font-size: 0.75rem;
            background: #f5f5f5;
            padding: 2px 5px;
            border-radius: 3px;
            word-break: break-all;
            margin-top: 5px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .file-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .login-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <?php if (!isset($_SESSION['loggedin'])): ?>
        <!-- LOGIN PAGE -->
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <i class="fas fa-cloud"></i>
                    <h2>OSIS Cloud Storage</h2>
                    <p class="text-muted">Login untuk mengakses file</p>
                </div>
                
                <?php if (isset($login_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($login_error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    
                    <button type="submit" name="login" class="btn btn-primary w-100">
                        <i class="fas fa-sign-in-alt me-2"></i> Login
                    </button>
                    
                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            Default users:<br>
                            admin / admin123<br>
                            osis / osis2024<br>
                            user / user123
                        </small>
                    </div>
                </form>
            </div>
        </div>
        
    <?php else: ?>
        <!-- MAIN APPLICATION -->
        <div class="alert-container">
            <?php if (isset($upload_success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $upload_success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($upload_error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($upload_error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($rename_success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $rename_success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($share_success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $share_success; ?>
                    <?php if (isset($share_link)): ?>
                        <br><small>Link: <code><?php echo htmlspecialchars($share_link); ?></code></small>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($delete_success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $delete_success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($sync_success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $sync_success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="app-container">
            <!-- Header -->
            <div class="app-header">
                <div class="header-content">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-cloud me-2"></i>OSIS Cloud Storage
                        </h1>
                        <small>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>!</small>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button class="btn btn-light" onclick="showUploadModal()">
                            <i class="fas fa-upload me-2"></i>Upload
                        </button>
                        
                        <a href="?sync=1" class="btn btn-light">
                            <i class="fas fa-sync-alt me-2"></i>Sync
                        </a>
                        
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="showUploadModal()">
                                    <i class="fas fa-upload me-2"></i>Upload File
                                </a></li>
                                <li><a class="dropdown-item" href="?sync=1">
                                    <i class="fas fa-sync-alt me-2"></i>Sync from Telegram
                                </a></li>
                                <li><a class="dropdown-item" href="#" onclick="showStatsModal()">
                                    <i class="fas fa-chart-bar me-2"></i>Statistics
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="?logout=1">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="container-fluid p-4">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <h4><?php echo $file_stats['total_files'] ?? 0; ?></h4>
                            <p class="text-muted mb-0">Total Files</p>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-hdd"></i>
                            </div>
                            <h4><?php echo formatBytes($file_stats['total_size'] ?? 0); ?></h4>
                            <p class="text-muted mb-0">Total Size</p>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <h4><?php echo $file_stats['user_files'] ?? 0; ?></h4>
                            <p class="text-muted mb-0">Your Files</p>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-cloud"></i>
                            </div>
                            <h4>Telegram</h4>
                            <p class="text-muted mb-0">Cloud Storage</p>
                        </div>
                    </div>
                </div>
                
                <!-- Search and Filter -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Search files..." id="searchInput">
                            <button class="btn btn-outline-secondary" type="button">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="btn-group w-100">
                            <button class="btn btn-outline-secondary active" onclick="filterFiles('all')">All</button>
                            <button class="btn btn-outline-secondary" onclick="filterFiles('image')">
                                <i class="fas fa-image"></i> Images
                            </button>
                            <button class="btn btn-outline-secondary" onclick="filterFiles('video')">
                                <i class="fas fa-video"></i> Videos
                            </button>
                            <button class="btn btn-outline-secondary" onclick="filterFiles('audio')">
                                <i class="fas fa-music"></i> Audio
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Files Grid -->
                <div id="filesGrid">
                    <?php if (empty($all_files)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-cloud-upload-alt fa-4x text-muted mb-3"></i>
                            <h3>No files yet</h3>
                            <p class="text-muted">Upload your first file to get started!</p>
                            <button class="btn btn-primary" onclick="showUploadModal()">
                                <i class="fas fa-upload me-2"></i>Upload First File
                            </button>
                            <a href="?sync=1" class="btn btn-outline-primary ms-2">
                                <i class="fas fa-sync-alt me-2"></i>Sync from Telegram
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="file-grid">
                            <?php foreach ($all_files as $file): ?>
                                <?php
                                $fileIcon = getFileIcon($file['display_name']);
                                $fileType = getFileType($file['display_name']);
                                $uploadDate = date('d M Y H:i', strtotime($file['upload_date']));
                                $fileSize = formatBytes($file['file_size']);
                                $shortFileId = substr($file['telegram_file_id'], 0, 15) . '...';
                                
                                $typeBadges = [
                                    'image' => 'badge-image',
                                    'video' => 'badge-video', 
                                    'audio' => 'badge-audio',
                                    'pdf' => 'badge-pdf',
                                    'other' => 'badge-other'
                                ];
                                $badgeClass = $typeBadges[$fileType] ?? 'badge-other';
                                ?>
                                
                                <div class="file-card" data-type="<?php echo $fileType; ?>">
                                    <div class="d-flex align-items-start mb-3">
                                        <div class="file-icon">
                                            <i class="fas <?php echo $fileIcon; ?>"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div class="file-name">
                                                <?php echo htmlspecialchars($file['display_name']); ?>
                                                <span class="badge <?php echo $badgeClass; ?> badge-type">
                                                    <?php echo strtoupper($fileType); ?>
                                                </span>
                                            </div>
                                            <div class="file-meta">
                                                <i class="fas fa-hdd me-1"></i> <?php echo $fileSize; ?>
                                            </div>
                                            <div class="file-meta">
                                                <i class="fas fa-calendar me-1"></i> <?php echo $uploadDate; ?>
                                            </div>
                                            <div class="file-meta">
                                                <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($file['uploaded_by_name'] ?? 'Unknown'); ?>
                                            </div>
                                            <?php if (!empty($file['caption'])): ?>
                                                <div class="file-meta">
                                                    <i class="fas fa-comment me-1"></i>
                                                    <?php echo htmlspecialchars(substr($file['caption'], 0, 50)); ?>
                                                    <?php if (strlen($file['caption']) > 50): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="file-id" title="<?php echo htmlspecialchars($file['telegram_file_id']); ?>">
                                                ID: <?php echo $shortFileId; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="file-actions">
                                        <a href="?download=1&file_id=<?php echo urlencode($file['telegram_file_id']); ?>" 
                                           class="btn btn-outline-primary btn-action">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                        
                                        <button class="btn btn-outline-secondary btn-action" 
                                                onclick="showRenameModal('<?php echo $file['telegram_file_id']; ?>', '<?php echo addslashes($file['display_name']); ?>')">
                                            <i class="fas fa-edit me-1"></i>Rename
                                        </button>
                                        
                                        <button class="btn btn-outline-info btn-action" 
                                                onclick="showShareModal('<?php echo $file['telegram_file_id']; ?>', '<?php echo addslashes($file['display_name']); ?>')">
                                            <i class="fas fa-share-alt me-1"></i>Share
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Upload Modal -->
        <div class="modal fade" id="uploadModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-upload me-2"></i>Upload File
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="upload-zone" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-cloud-upload-alt fa-3x mb-3 text-primary"></i>
                                <h5>Click to select file or drag and drop</h5>
                                <p class="text-muted">Maximum file size: 2GB</p>
                                <input type="file" name="fileupload" id="fileInput" class="d-none" required>
                            </div>
                            
                            <div id="fileInfo" class="mt-3 d-none">
                                <div class="mb-3">
                                    <label class="form-label">File Name</label>
                                    <input type="text" class="form-control" id="fileNameDisplay" readonly>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">File Size</label>
                                    <input type="text" class="form-control" id="fileSizeDisplay" readonly>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Caption (Optional)</label>
                                <input type="text" class="form-control" name="caption" placeholder="Add a description...">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="upload" class="btn btn-primary" id="uploadBtn" disabled>
                                <i class="fas fa-upload me-2"></i>Upload to Cloud
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Rename Modal -->
        <div class="modal fade" id="renameModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-edit me-2"></i>Rename File
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="file_id" id="renameFileId">
                            
                            <div class="mb-3">
                                <label class="form-label">Current Name</label>
                                <input type="text" class="form-control" id="currentNameDisplay" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">New Name</label>
                                <input type="text" class="form-control" name="new_name" id="newFileName" required>
                                <small class="text-muted">Include file extension</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="rename" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Share Modal -->
        <div class="modal fade" id="shareModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-share-alt me-2"></i>Share File
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="file_id" id="shareFileId">
                            
                            <div class="mb-3">
                                <label class="form-label">File to Share</label>
                                <input type="text" class="form-control" id="shareFileDisplay" readonly>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Share link will be valid for 24 hours
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="share" class="btn btn-primary">
                                <i class="fas fa-link me-2"></i>Generate Share Link
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Statistics Modal -->
        <div class="modal fade" id="statsModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-chart-bar me-2"></i>Storage Statistics
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6>Database Info</h6>
                                        <p class="mb-1">Database File: <?php echo DB_FILE; ?></p>
                                        <p class="mb-1">Size: <?php echo file_exists(DB_FILE) ? formatBytes(filesize(DB_FILE)) : 'Not found'; ?></p>
                                        <p class="mb-0">Last Modified: <?php echo file_exists(DB_FILE) ? date('d M Y H:i', filemtime(DB_FILE)) : 'N/A'; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6>User Info</h6>
                                        <p class="mb-1">Username: <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                                        <p class="mb-1">Full Name: <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'N/A'); ?></p>
                                        <p class="mb-0">Login Time: <?php echo date('H:i:s', $_SESSION['login_time'] ?? time()); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>File Type</th>
                                        <th>Count</th>
                                        <th>Total Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $db->query("
                                        SELECT 
                                            CASE 
                                                WHEN mime_type LIKE 'image/%' THEN 'Image'
                                                WHEN mime_type LIKE 'video/%' THEN 'Video'
                                                WHEN mime_type LIKE 'audio/%' THEN 'Audio'
                                                WHEN mime_type = 'application/pdf' THEN 'PDF'
                                                ELSE 'Other'
                                            END as file_type,
                                            COUNT(*) as count,
                                            SUM(file_size) as total_size
                                        FROM files 
                                        GROUP BY 
                                            CASE 
                                                WHEN mime_type LIKE 'image/%' THEN 'Image'
                                                WHEN mime_type LIKE 'video/%' THEN 'Video'
                                                WHEN mime_type LIKE 'audio/%' THEN 'Audio'
                                                WHEN mime_type = 'application/pdf' THEN 'PDF'
                                                ELSE 'Other'
                                            END
                                    ");
                                    $type_stats = $stmt->fetchAll();
                                    
                                    foreach ($type_stats as $stat): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($stat['file_type']); ?></td>
                                        <td><?php echo $stat['count']; ?></td>
                                        <td><?php echo formatBytes($stat['total_size']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Inisialisasi modal
            const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
            const renameModal = new bootstrap.Modal(document.getElementById('renameModal'));
            const shareModal = new bootstrap.Modal(document.getElementById('shareModal'));
            const statsModal = new bootstrap.Modal(document.getElementById('statsModal'));
            
            // File upload handling
            document.getElementById('fileInput')?.addEventListener('change', function(e) {
                const file = this.files[0];
                if (file) {
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        alert('File too large (max 2GB)');
                        this.value = '';
                        return;
                    }
                    
                    document.getElementById('fileNameDisplay').value = file.name;
                    document.getElementById('fileSizeDisplay').value = formatBytes(file.size);
                    document.getElementById('fileInfo').classList.remove('d-none');
                    document.getElementById('uploadBtn').disabled = false;
                }
            });
            
            // Drag and drop
            const uploadZone = document.querySelector('.upload-zone');
            if (uploadZone) {
                uploadZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadZone.style.borderColor = '#3498db';
                    uploadZone.style.background = '#e3f2fd';
                });
                
                uploadZone.addEventListener('dragleave', (e) => {
                    e.preventDefault();
                    uploadZone.style.borderColor = '#ddd';
                    uploadZone.style.background = '#f8f9fa';
                });
                
                uploadZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadZone.style.borderColor = '#ddd';
                    uploadZone.style.background = '#f8f9fa';
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const fileInput = document.getElementById('fileInput');
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(files[0]);
                        fileInput.files = dataTransfer.files;
                        fileInput.dispatchEvent(new Event('change'));
                    }
                });
            }
            
            // Modal functions
            function showUploadModal() {
                uploadModal.show();
            }
            
            function showRenameModal(fileId, fileName) {
                document.getElementById('renameFileId').value = fileId;
                document.getElementById('currentNameDisplay').value = fileName;
                document.getElementById('newFileName').value = fileName.split('.')[0];
                renameModal.show();
            }
            
            function showShareModal(fileId, fileName) {
                document.getElementById('shareFileId').value = fileId;
                document.getElementById('shareFileDisplay').value = fileName;
                shareModal.show();
            }
            
            function showStatsModal() {
                statsModal.show();
            }
            
            // Search and filter
            function filterFiles(type) {
                const cards = document.querySelectorAll('.file-card');
                const buttons = document.querySelectorAll('.btn-group .btn');
                
                buttons.forEach(btn => btn.classList.remove('active'));
                event.target.classList.add('active');
                
                cards.forEach(card => {
                    if (type === 'all' || card.dataset.type === type) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
            
            document.getElementById('searchInput')?.addEventListener('input', function(e) {
                const query = this.value.toLowerCase();
                const cards = document.querySelectorAll('.file-card');
                
                cards.forEach(card => {
                    const fileName = card.querySelector('.file-name').textContent.toLowerCase();
                    if (fileName.includes(query)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
            
            // Utility function
            function formatBytes(bytes, decimals = 2) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const dm = decimals < 0 ? 0 : decimals;
                const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getInstance(alert)?.close();
                });
            }, 5000);
        </script>
    <?php endif; ?>
</body>
</html>
