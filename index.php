<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// KONFIGURASI
// ============================================
define('DB_FILE', 'osis_cloud.db');
define('BOT_TOKEN', '8401425763:AAGzfWOOETNcocI7JCj9zQxBhZZ2fVaworI');
define('CHAT_ID', '-1003838508884');
define('API_URL', 'https://api.telegram.org/bot');
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('CHUNK_SIZE', 10 * 1024 * 1024); // 10MB chunks untuk upload besar
define('UPLOAD_TIMEOUT', 300); // 5 menit

// Base URL
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
            is_deleted INTEGER DEFAULT 0,
            upload_status TEXT DEFAULT 'completed',
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS upload_chunks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            chunk_index INTEGER,
            chunk_size INTEGER,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (file_id) REFERENCES files(id)
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
// FUNGSI UTILITAS
// ============================================
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
        'gif' => 'fa-image', 'webp' => 'fa-image', 'bmp' => 'fa-image', 'svg' => 'fa-image',
        'mp4' => 'fa-video', 'avi' => 'fa-video', 'mov' => 'fa-video', 'mkv' => 'fa-video',
        'mp3' => 'fa-music', 'wav' => 'fa-music', 'ogg' => 'fa-music', 'm4a' => 'fa-music',
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive', '7z' => 'fa-file-archive',
        'txt' => 'fa-file-alt'
    ];
    return isset($icons[$ext]) ? $icons[$ext] : 'fa-file';
}

function getFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    $videos = ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm'];
    $audios = ['mp3', 'wav', 'ogg', 'm4a', 'flac'];
    
    if (in_array($ext, $images)) return 'image';
    if (in_array($ext, $videos)) return 'video';
    if (in_array($ext, $audios)) return 'audio';
    if ($ext == 'pdf') return 'pdf';
    return 'other';
}

function canPreview($filename) {
    $type = getFileType($filename);
    return in_array($type, ['image', 'video', 'audio', 'pdf']);
}

function getPreviewType($filename) {
    $type = getFileType($filename);
    if ($type == 'pdf') return 'pdf';
    return $type;
}

// ============================================
// TELEGRAM API FUNCTIONS dengan STREAMING
// ============================================
function telegramStreamUpload($file_path, $filename, $caption = '') {
    if (!file_exists($file_path)) {
        return ['ok' => false, 'error' => 'File not found'];
    }
    
    $fileSize = filesize($file_path);
    if ($fileSize > MAX_FILE_SIZE) {
        return ['ok' => false, 'error' => 'File too large (max 2GB)'];
    }
    
    // Untuk file kecil (< 10MB), upload langsung
    if ($fileSize <= 10 * 1024 * 1024) {
        return uploadSmallFile($file_path, $filename, $caption);
    }
    
    // Untuk file besar, gunakan chunking
    return uploadLargeFile($file_path, $filename, $caption);
}

function uploadSmallFile($file_path, $filename, $caption) {
    $cfile = new CURLFile($file_path, mime_content_type($file_path), $filename);
    
    $params = [
        'chat_id' => CHAT_ID,
        'document' => $cfile,
        'caption' => substr($caption, 0, 1024)
    ];
    
    return telegramRequest('sendDocument', $params);
}

function uploadLargeFile($file_path, $filename, $caption) {
    $fileSize = filesize($file_path);
    $totalChunks = ceil($fileSize / CHUNK_SIZE);
    $file_id = null;
    
    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        return ['ok' => false, 'error' => 'Cannot open file'];
    }
    
    // Upload per chunk dengan timeout rendah
    for ($i = 0; $i < $totalChunks; $i++) {
        $offset = $i * CHUNK_SIZE;
        $chunkSize = ($i == $totalChunks - 1) ? $fileSize - $offset : CHUNK_SIZE;
        
        // Baca chunk
        fseek($handle, $offset);
        $chunkData = fread($handle, $chunkSize);
        
        // Simpan ke temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'chunk_');
        file_put_contents($tempFile, $chunkData);
        
        $cfile = new CURLFile($tempFile, 'application/octet-stream', $filename);
        
        $params = [
            'chat_id' => CHAT_ID,
            'document' => $cfile,
            'caption' => ($i === 0) ? substr($caption, 0, 1024) : ''
        ];
        
        $result = telegramRequest('sendDocument', $params, 60); // Timeout 60 detik per chunk
        
        // Hapus temporary file
        unlink($tempFile);
        
        if (!$result['ok']) {
            fclose($handle);
            return $result;
        }
        
        if ($i === 0) {
            $file_id = $result['result']['document']['file_id'];
        }
        
        // Update progress (simpan di session)
        $progress = round(($i + 1) / $totalChunks * 100);
        $_SESSION['upload_progress'] = $progress;
        session_write_close(); // Lepas lock session
        session_start(); // Mulai lagi
        
        // Beri waktu istirahat antara chunk
        if ($i < $totalChunks - 1) {
            sleep(1);
        }
    }
    
    fclose($handle);
    
    if ($file_id) {
        return [
            'ok' => true,
            'file_id' => $file_id,
            'file_name' => $filename,
            'file_size' => $fileSize,
            'mime_type' => mime_content_type($file_path)
        ];
    }
    
    return ['ok' => false, 'error' => 'Upload failed'];
}

function telegramRequest($method, $params = [], $timeout = 30) {
    $url = API_URL . BOT_TOKEN . '/' . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
    curl_close($ch);
    
    if ($error) {
        return ['ok' => false, 'error' => "CURL Error: $error"];
    }
    
    $data = json_decode($response, true);
    return $data;
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
// DATABASE FUNCTIONS
// ============================================
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
        return false;
    }
}

function getAllFiles() {
    global $db;
    $stmt = $db->prepare("
        SELECT f.*, u.username as uploaded_by_name 
        FROM files f 
        LEFT JOIN users u ON f.uploaded_by = u.id 
        WHERE f.is_deleted = 0 AND f.upload_status = 'completed'
        ORDER BY f.upload_date DESC 
        LIMIT 100
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getFileByTelegramId($telegram_file_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT f.*, u.username as uploaded_by_name 
        FROM files f 
        LEFT JOIN users u ON f.uploaded_by = u.id 
        WHERE f.telegram_file_id = ? AND f.is_deleted = 0
    ");
    $stmt->execute([$telegram_file_id]);
    return $stmt->fetch();
}

function updateFileName($telegram_file_id, $new_name) {
    global $db;
    
    try {
        $stmt = $db->prepare("UPDATE files SET display_name = ? WHERE telegram_file_id = ?");
        $stmt->execute([$new_name, $telegram_file_id]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function deleteFile($telegram_file_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("UPDATE files SET is_deleted = 1 WHERE telegram_file_id = ?");
        return $stmt->execute([$telegram_file_id]);
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================
// SISTEM LOGIN
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
        
        header("Location: " . $base_url);
        exit;
    } else {
        $login_error = "Username atau password salah!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $base_url);
    exit;
}

// ============================================
// HANDLE UPLOAD FILE dengan AJAX STREAMING
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_upload') {
    if (!isset($_SESSION['loggedin'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $filename = $_POST['filename'] ?? '';
    $filesize = intval($_POST['filesize'] ?? 0);
    
    if ($filesize > MAX_FILE_SIZE) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 2GB)']);
        exit;
    }
    
    // Generate unique ID untuk upload session
    $upload_id = uniqid('upload_', true);
    $_SESSION['upload_queue'][$upload_id] = [
        'filename' => $filename,
        'filesize' => $filesize,
        'chunks' => [],
        'progress' => 0,
        'status' => 'pending'
    ];
    
    echo json_encode(['success' => true, 'upload_id' => $upload_id]);
    exit;
}

// Endpoint untuk upload chunk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_chunk') {
    if (!isset($_SESSION['loggedin'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $upload_id = $_POST['upload_id'] ?? '';
    $chunk_index = intval($_POST['chunk_index'] ?? 0);
    $chunk_data = $_POST['chunk_data'] ?? '';
    
    if (empty($upload_id) || !isset($_SESSION['upload_queue'][$upload_id])) {
        echo json_encode(['success' => false, 'error' => 'Invalid upload session']);
        exit;
    }
    
    // Decode chunk data (base64)
    $chunk_binary = base64_decode($chunk_data);
    
    // Simpan chunk ke temporary file
    $temp_dir = sys_get_temp_dir() . '/osis_uploads/';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    $chunk_file = $temp_dir . $upload_id . '_chunk_' . $chunk_index;
    file_put_contents($chunk_file, $chunk_binary);
    
    // Simpan info chunk
    $_SESSION['upload_queue'][$upload_id]['chunks'][$chunk_index] = $chunk_file;
    $_SESSION['upload_queue'][$upload_id]['progress'] = ($chunk_index + 1) / ceil($_SESSION['upload_queue'][$upload_id]['filesize'] / CHUNK_SIZE) * 100;
    
    echo json_encode(['success' => true, 'progress' => $_SESSION['upload_queue'][$upload_id]['progress']]);
    exit;
}

// Endpoint untuk complete upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_upload') {
    if (!isset($_SESSION['loggedin'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $upload_id = $_POST['upload_id'] ?? '';
    
    if (empty($upload_id) || !isset($_SESSION['upload_queue'][$upload_id])) {
        echo json_encode(['success' => false, 'error' => 'Invalid upload session']);
        exit;
    }
    
    $upload_info = $_SESSION['upload_queue'][$upload_id];
    
    try {
        // Gabungkan semua chunk menjadi satu file
        $temp_file = sys_get_temp_dir() . '/osis_uploads/' . $upload_id . '_complete';
        $fp = fopen($temp_file, 'wb');
        
        ksort($upload_info['chunks']);
        foreach ($upload_info['chunks'] as $chunk_file) {
            $chunk_data = file_get_contents($chunk_file);
            fwrite($fp, $chunk_data);
            unlink($chunk_file); // Hapus chunk file
        }
        fclose($fp);
        
        // Upload ke Telegram
        $caption = pathinfo($upload_info['filename'], PATHINFO_FILENAME);
        $result = telegramStreamUpload($temp_file, $upload_info['filename'], $caption);
        
        // Hapus temporary file
        unlink($temp_file);
        
        if ($result['ok']) {
            // Simpan ke database
            saveFileToDB(
                $result['file_id'],
                $result['file_name'],
                $result['file_size'],
                $result['mime_type'],
                $caption,
                $_SESSION['user_id']
            );
            
            // Hapus dari upload queue
            unset($_SESSION['upload_queue'][$upload_id]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'File uploaded successfully!',
                'file_id' => $result['file_id']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Telegram upload failed: ' . ($result['error'] ?? 'Unknown error')]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Endpoint untuk get upload progress
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_progress') {
    if (!isset($_SESSION['loggedin'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $upload_id = $_GET['upload_id'] ?? '';
    
    if (empty($upload_id) || !isset($_SESSION['upload_queue'][$upload_id])) {
        echo json_encode(['success' => false, 'error' => 'Invalid upload session']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'progress' => $_SESSION['upload_queue'][$upload_id]['progress'],
        'filename' => $_SESSION['upload_queue'][$upload_id]['filename']
    ]);
    exit;
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
            
            // Stream file dari Telegram
            $ch = curl_init($file_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $file_content = curl_exec($ch);
            curl_close($ch);
            
            echo $file_content;
            exit;
        }
    }
}

// ============================================
// LOAD FILES
// ============================================
$all_files = [];
if (isset($_SESSION['loggedin'])) {
    $all_files = getAllFiles();
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
            --primary: #1a237e;
            --secondary: #283593;
            --accent: #3949ab;
            --success: #2e7d32;
            --warning: #f57c00;
            --danger: #c62828;
            --light: #f5f5f5;
            --dark: #0d47a1;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
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
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
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
        
        /* Upload Zone */
        .upload-zone {
            border: 3px dashed #ddd;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
            margin: 20px;
            position: relative;
        }
        
        .upload-zone:hover {
            border-color: var(--accent);
            background: #e3f2fd;
        }
        
        .upload-zone.dragover {
            border-color: var(--success);
            background: #e8f5e9;
        }
        
        /* Progress Bar */
        .upload-progress {
            width: 100%;
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin: 20px 0;
            display: none;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }
        
        /* File Cards */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .file-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
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
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .file-card:hover .file-actions {
            opacity: 1;
        }
        
        .btn-action {
            flex: 1;
            font-size: 0.85rem;
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
        
        /* Preview Modal */
        .preview-modal .modal-content {
            border-radius: 15px;
            overflow: hidden;
        }
        
        .preview-content {
            text-align: center;
            padding: 20px;
        }
        
        .preview-image {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        video, audio {
            width: 100%;
            border-radius: 10px;
            margin: 10px 0;
        }
        
        .pdf-viewer {
            width: 100%;
            height: 70vh;
            border: 1px solid #ddd;
            border-radius: 10px;
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
        
        /* Upload Status */
        .upload-status {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            max-width: 300px;
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
                        <input type="text" class="form-control" name="username" required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    
                    <button type="submit" name="login" class="btn btn-primary w-100">
                        <i class="fas fa-sign-in-alt me-2"></i> Login
                    </button>
                </form>
            </div>
        </div>
        
    <?php else: ?>
        <!-- MAIN APPLICATION -->
        <div class="alert-container" id="alertContainer"></div>
        
        <!-- Upload Status -->
        <div class="upload-status" id="uploadStatus">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Uploading...</strong>
                <button type="button" class="btn-close" onclick="hideUploadStatus()"></button>
            </div>
            <div class="upload-progress">
                <div class="progress-bar" id="uploadProgressBar">0%</div>
            </div>
            <small id="uploadFileName"></small>
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
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="document.getElementById('fileInput').click()">
                                    <i class="fas fa-plus me-2"></i>Add File
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
            
            <!-- Upload Zone -->
            <div class="upload-zone" id="uploadZone">
                <i class="fas fa-cloud-upload-alt fa-3x mb-3 text-primary"></i>
                <h5>Click to select file or drag and drop</h5>
                <p class="text-muted">Maximum file size: 2GB (Supports large files)</p>
                <input type="file" id="fileInput" class="d-none">
                
                <div class="upload-progress" id="progressContainer">
                    <div class="progress-bar" id="progressBar">0%</div>
                </div>
            </div>
            
            <!-- Files Grid -->
            <div class="file-grid" id="filesGrid">
                <?php if (empty($all_files)): ?>
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-cloud-upload-alt fa-4x text-muted mb-3"></i>
                        <h3>No files yet</h3>
                        <p class="text-muted mb-4">Drag and drop a file to upload!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_files as $file): ?>
                        <?php
                        $fileIcon = getFileIcon($file['display_name']);
                        $fileType = getFileType($file['display_name']);
                        $uploadDate = date('d M Y H:i', strtotime($file['upload_date']));
                        $fileSize = formatBytes($file['file_size']);
                        $canPreview = canPreview($file['display_name']);
                        $previewType = getPreviewType($file['display_name']);
                        ?>
                        
                        <div class="file-card" 
                             data-file-id="<?php echo $file['telegram_file_id']; ?>"
                             data-file-name="<?php echo htmlspecialchars($file['display_name']); ?>"
                             data-file-type="<?php echo $previewType; ?>"
                             data-can-preview="<?php echo $canPreview ? '1' : '0'; ?>">
                            <div class="d-flex align-items-start mb-3">
                                <div class="file-icon">
                                    <i class="fas <?php echo $fileIcon; ?>"></i>
                                </div>
                                <div style="flex: 1;">
                                    <div class="file-name">
                                        <?php echo htmlspecialchars($file['display_name']); ?>
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
                                </div>
                            </div>
                            
                            <div class="file-actions">
                                <a href="?download=1&file_id=<?php echo urlencode($file['telegram_file_id']); ?>" 
                                   class="btn btn-outline-primary btn-action">
                                    <i class="fas fa-download me-1"></i>
                                </a>
                                
                                <button class="btn btn-outline-danger btn-action" 
                                        onclick="deleteFile('<?php echo $file['telegram_file_id']; ?>', '<?php echo addslashes($file['display_name']); ?>')">
                                    <i class="fas fa-trash me-1"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Preview Modal -->
        <div class="modal fade preview-modal" id="previewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="previewTitle"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="preview-content" id="previewContent"></div>
                    </div>
                    <div class="modal-footer">
                        <a href="#" class="btn btn-primary" id="previewDownloadBtn">
                            <i class="fas fa-download me-2"></i>Download
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Upload Manager dengan Chunking untuk file besar
            class UploadManager {
                constructor() {
                    this.chunkSize = 10 * 1024 * 1024; // 10MB
                    this.currentUploadId = null;
                    this.file = null;
                    this.chunks = [];
                    this.totalChunks = 0;
                    this.uploadedChunks = 0;
                    this.uploadStatus = document.getElementById('uploadStatus');
                    this.uploadProgressBar = document.getElementById('uploadProgressBar');
                    this.uploadFileName = document.getElementById('uploadFileName');
                    this.progressContainer = document.getElementById('progressContainer');
                    this.progressBar = document.getElementById('progressBar');
                }
                
                async startUpload(file) {
                    try {
                        this.file = file;
                        this.totalChunks = Math.ceil(file.size / this.chunkSize);
                        
                        // Start upload session
                        const response = await fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'start_upload',
                                filename: file.name,
                                filesize: file.size
                            })
                        });
                        
                        const data = await response.json();
                        if (!data.success) {
                            throw new Error(data.error);
                        }
                        
                        this.currentUploadId = data.upload_id;
                        
                        // Show upload status
                        this.showUploadStatus(file.name);
                        
                        // Upload chunks
                        await this.uploadChunks();
                        
                        // Complete upload
                        await this.completeUpload();
                        
                        this.showAlert('File uploaded successfully!', 'success');
                        setTimeout(() => location.reload(), 2000);
                        
                    } catch (error) {
                        console.error('Upload error:', error);
                        this.showAlert('Upload failed: ' + error.message, 'error');
                    } finally {
                        this.hideUploadStatus();
                    }
                }
                
                async uploadChunks() {
                    for (let i = 0; i < this.totalChunks; i++) {
                        const start = i * this.chunkSize;
                        const end = Math.min(start + this.chunkSize, this.file.size);
                        const chunk = this.file.slice(start, end);
                        
                        // Read chunk as base64
                        const chunkBase64 = await this.readChunkAsBase64(chunk);
                        
                        // Upload chunk
                        const formData = new URLSearchParams({
                            action: 'upload_chunk',
                            upload_id: this.currentUploadId,
                            chunk_index: i,
                            chunk_data: chunkBase64
                        });
                        
                        const response = await fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: formData
                        });
                        
                        const data = await response.json();
                        if (!data.success) {
                            throw new Error('Chunk upload failed');
                        }
                        
                        this.uploadedChunks++;
                        const progress = Math.round((this.uploadedChunks / this.totalChunks) * 100);
                        this.updateProgress(progress);
                        
                        // Check progress from server
                        await this.checkProgress();
                    }
                }
                
                async completeUpload() {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'complete_upload',
                            upload_id: this.currentUploadId
                        })
                    });
                    
                    const data = await response.json();
                    if (!data.success) {
                        throw new Error(data.error || 'Upload completion failed');
                    }
                    
                    return data;
                }
                
                async checkProgress() {
                    const response = await fetch(`?action=get_progress&upload_id=${this.currentUploadId}`);
                    const data = await response.json();
                    if (data.success) {
                        this.updateProgress(data.progress);
                    }
                }
                
                readChunkAsBase64(chunk) {
                    return new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.onload = () => {
                            const base64 = reader.result.split(',')[1];
                            resolve(base64);
                        };
                        reader.onerror = reject;
                        reader.readAsDataURL(chunk);
                    });
                }
                
                updateProgress(percentage) {
                    this.progressBar.style.width = percentage + '%';
                    this.progressBar.textContent = percentage + '%';
                    
                    this.uploadProgressBar.style.width = percentage + '%';
                    this.uploadProgressBar.textContent = percentage + '%';
                }
                
                showUploadStatus(filename) {
                    this.uploadFileName.textContent = filename;
                    this.progressContainer.style.display = 'block';
                    this.uploadStatus.style.display = 'block';
                }
                
                hideUploadStatus() {
                    this.uploadStatus.style.display = 'none';
                    this.progressContainer.style.display = 'none';
                    this.progressBar.style.width = '0%';
                    this.progressBar.textContent = '0%';
                }
                
                showAlert(message, type = 'info') {
                    const alertContainer = document.getElementById('alertContainer');
                    const alert = document.createElement('div');
                    alert.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show`;
                    alert.innerHTML = `
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    alertContainer.appendChild(alert);
                    
                    setTimeout(() => {
                        alert.remove();
                    }, 5000);
                }
            }
            
            // Initialize upload manager
            const uploadManager = new UploadManager();
            
            // File input handling
            document.getElementById('fileInput').addEventListener('change', async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                
                if (file.size > <?php echo MAX_FILE_SIZE; ?>) {
                    alert('File too large (max 2GB)');
                    return;
                }
                
                await uploadManager.startUpload(file);
                e.target.value = '';
            });
            
            // Drag and drop
            const uploadZone = document.getElementById('uploadZone');
            
            uploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadZone.classList.add('dragover');
            });
            
            uploadZone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadZone.classList.remove('dragover');
            });
            
            uploadZone.addEventListener('drop', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadZone.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    
                    if (file.size > <?php echo MAX_FILE_SIZE; ?>) {
                        alert('File too large (max 2GB)');
                        return;
                    }
                    
                    await uploadManager.startUpload(file);
                }
            });
            
            // Click to select file
            uploadZone.addEventListener('click', () => {
                document.getElementById('fileInput').click();
            });
            
            // Double click untuk preview file
            document.querySelectorAll('.file-card').forEach(card => {
                card.addEventListener('dblclick', function() {
                    const canPreview = this.dataset.canPreview === '1';
                    const fileId = this.dataset.fileId;
                    const fileName = this.dataset.fileName;
                    const fileType = this.dataset.fileType;
                    
                    if (canPreview) {
                        showPreview(fileId, fileName, fileType);
                    } else {
                        window.location.href = `?download=1&file_id=${encodeURIComponent(fileId)}`;
                    }
                });
            });
            
            // Preview Modal
            const previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
            
            function showPreview(fileId, fileName, fileType) {
                const previewUrl = `?download=1&file_id=${encodeURIComponent(fileId)}`;
                
                document.getElementById('previewTitle').textContent = fileName;
                document.getElementById('previewDownloadBtn').href = previewUrl;
                
                const previewContent = document.getElementById('previewContent');
                previewContent.innerHTML = '';
                
                switch(fileType) {
                    case 'image':
                        previewContent.innerHTML = `
                            <img src="${previewUrl}" class="preview-image" alt="${fileName}" onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300?text=Preview+Not+Available'">
                        `;
                        break;
                        
                    case 'video':
                        previewContent.innerHTML = `
                            <video controls class="preview-video">
                                <source src="${previewUrl}" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        `;
                        break;
                        
                    case 'audio':
                        previewContent.innerHTML = `
                            <audio controls class="preview-audio">
                                <source src="${previewUrl}" type="audio/mpeg">
                                Your browser does not support the audio tag.
                            </audio>
                            <p class="mt-2">${fileName}</p>
                        `;
                        break;
                        
                    case 'pdf':
                        previewContent.innerHTML = `
                            <div class="text-center">
                                <i class="fas fa-file-pdf fa-4x text-danger mb-3"></i>
                                <p>PDF Preview not available in browser</p>
                                <a href="${previewUrl}" class="btn btn-primary">
                                    <i class="fas fa-download me-2"></i>Download PDF
                                </a>
                            </div>
                        `;
                        break;
                }
                
                previewModal.show();
            }
            
            function deleteFile(fileId, fileName) {
                if (confirm(`Are you sure you want to delete "${fileName}"?`)) {
                    window.location.href = `?delete=1&file_id=${encodeURIComponent(fileId)}`;
                }
            }
            
            function hideUploadStatus() {
                document.getElementById('uploadStatus').style.display = 'none';
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (e.ctrlKey && e.key === 'u') {
                    e.preventDefault();
                    document.getElementById('fileInput').click();
                }
                
                if (e.key === 'Escape') {
                    hideUploadStatus();
                }
            });
            
            // Auto-hide alerts
            setInterval(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    if (alert.parentElement) {
                        bootstrap.Alert.getInstance(alert)?.close();
                    }
                });
            }, 5000);
        </script>
    <?php endif; ?>
</body>
</html>
