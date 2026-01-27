<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// KONFIGURASI
// ============================================
define('BOT_TOKEN', '8401425763:AAGzfWOOETNcocI7JCj9zQxBhZZ2fVaworI');
define('CHAT_ID', '-1003838508884');
define('API_URL', 'https://api.telegram.org/bot');
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('UPLOAD_TIMEOUT', 300); // 5 menit

// Base URL untuk link sharing
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

// Inisialisasi storage jika belum ada
if (!isset($_SESSION['storage'])) {
    $_SESSION['storage'] = [
        'files' => [],    // Menyimpan semua file data
        'renamed' => [],  // File rename mapping
        'shares' => []    // Share tokens
    ];
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
        'gif' => 'fa-image', 'webp' => 'fa-image', 'bmp' => 'fa-image',
        'mp4' => 'fa-video', 'avi' => 'fa-video', 'mov' => 'fa-video', 
        'mkv' => 'fa-video', 'flv' => 'fa-video', 'wmv' => 'fa-video',
        'mp3' => 'fa-music', 'wav' => 'fa-music', 'ogg' => 'fa-music', 
        'm4a' => 'fa-music', 'flac' => 'fa-music',
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive', 
        '7z' => 'fa-file-archive', 'tar' => 'fa-file-archive',
        'txt' => 'fa-file-alt', 'csv' => 'fa-file-csv',
        'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint'
    ];
    return isset($icons[$ext]) ? $icons[$ext] : 'fa-file';
}

function getFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    $videos = ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm'];
    $audios = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];
    
    if (in_array($ext, $images)) return 'image';
    if (in_array($ext, $videos)) return 'video';
    if (in_array($ext, $audios)) return 'audio';
    if ($ext == 'pdf') return 'pdf';
    return 'other';
}

function sanitizeFilename($filename) {
    return preg_replace('/[^\w\.\-]/', '_', $filename);
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
        
        // Jika ada file, gunakan multipart/form-data
        $hasFile = false;
        foreach ($params as $param) {
            if ($param instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }
        
        if ($hasFile) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: multipart/form-data'
            ]);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
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
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => "JSON Error: " . json_last_error_msg()];
    }
    
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
    
    // Bagi file besar menjadi chunk (50MB per chunk)
    $chunkSize = 50 * 1024 * 1024; // 50MB
    if ($fileSize > $chunkSize) {
        return uploadLargeFile($file_path, $filename, $caption, $chunkSize);
    }
    
    // Upload file kecil langsung
    $cfile = new CURLFile($file_path, mime_content_type($file_path), $filename);
    
    $params = [
        'chat_id' => CHAT_ID,
        'document' => $cfile,
        'caption' => substr($caption, 0, 1024) // Telegram caption limit
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

function uploadLargeFile($file_path, $filename, $caption, $chunkSize) {
    // Upload file besar secara chunk
    $fileSize = filesize($file_path);
    $totalChunks = ceil($fileSize / $chunkSize);
    $file_id = null;
    
    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        return ['ok' => false, 'error' => 'Cannot open file'];
    }
    
    for ($i = 0; $i < $totalChunks; $i++) {
        $offset = $i * $chunkSize;
        $chunkData = fread($handle, $chunkSize);
        
        // Simpan chunk ke file temporary
        $tempFile = tempnam(sys_get_temp_dir(), 'chunk_');
        file_put_contents($tempFile, $chunkData);
        
        $cfile = new CURLFile($tempFile, 'application/octet-stream', $filename);
        
        $params = [
            'chat_id' => CHAT_ID,
            'document' => $cfile,
            'caption' => ($i === 0) ? substr($caption, 0, 1024) : ''
        ];
        
        $result = telegramRequest('sendDocument', $params);
        
        // Hapus file temporary
        unlink($tempFile);
        
        if (!$result['ok']) {
            fclose($handle);
            return $result;
        }
        
        if ($i === 0) {
            $file_id = $result['result']['document']['file_id'];
        }
        
        // Progress (opsional)
        $progress = round(($i + 1) / $totalChunks * 100);
        // Bisa disimpan di session untuk progress bar
        $_SESSION['upload_progress'] = $progress;
    }
    
    fclose($handle);
    
    if ($file_id) {
        return [
            'ok' => true,
            'file_id' => $file_id,
            'file_name' => $filename,
            'file_size' => $fileSize,
            'mime_type' => 'application/octet-stream'
        ];
    }
    
    return ['ok' => false, 'error' => 'Upload failed'];
}

function getFilesFromTelegram() {
    $result = telegramRequest('getUpdates', [
        'offset' => -100,
        'limit' => 100
    ]);
    
    $files = [];
    
    if ($result['ok']) {
        foreach ($result['result'] as $update) {
            if (isset($update['channel_post']['document'])) {
                $post = $update['channel_post'];
                $doc = $post['document'];
                
                $file_id = $doc['file_id'];
                $file_name = $doc['file_name'] ?? 'file_' . substr($file_id, 0, 10);
                $file_size = $doc['file_size'] ?? 0;
                $mime_type = $doc['mime_type'] ?? 'application/octet-stream';
                $caption = $post['caption'] ?? '';
                $date = $post['date'];
                
                // Cek apakah sudah ada di storage
                $exists = false;
                foreach ($_SESSION['storage']['files'] as $stored) {
                    if ($stored['file_id'] === $file_id) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $_SESSION['storage']['files'][] = [
                        'file_id' => $file_id,
                        'original_name' => $file_name,
                        'display_name' => $file_name,
                        'size' => $file_size,
                        'mime_type' => $mime_type,
                        'caption' => $caption,
                        'date' => $date,
                        'uploaded_by' => 'telegram',
                        'upload_time' => time()
                    ];
                }
                
                $display_name = $_SESSION['storage']['renamed'][$file_id] ?? $file_name;
                
                $files[] = [
                    'file_id' => $file_id,
                    'display_name' => $display_name,
                    'size' => $file_size,
                    'mime_type' => $mime_type,
                    'caption' => $caption,
                    'date' => $date,
                    'uploaded_by' => 'telegram'
                ];
            }
        }
    }
    
    return $files;
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
// SISTEM LOGIN (Simple Session)
// ============================================
$users = [
    'admin' => 'admin123',
    'osis' => 'osis2024',
    'user' => 'user123'
];

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (isset($users[$username]) && $users[$username] === $password) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        
        // Inisialisasi storage
        $_SESSION['storage'] = [
            'files' => [],
            'renamed' => [],
            'shares' => []
        ];
        
        // Redirect untuk menghindari resubmit form
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
// HANDLE UPLOAD FILE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload']) && isset($_SESSION['loggedin'])) {
    if (isset($_FILES['fileupload']) && $_FILES['fileupload']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['fileupload'];
        $caption = $_POST['caption'] ?? '';
        
        // Validasi file size
        if ($file['size'] > MAX_FILE_SIZE) {
            $upload_error = "File terlalu besar (maksimal 2GB)";
        } else {
            // Upload ke Telegram
            $result = uploadToTelegram($file['tmp_name'], sanitizeFilename($file['name']), $caption);
            
            if ($result['ok']) {
                // Simpan ke storage session
                $_SESSION['storage']['files'][] = [
                    'file_id' => $result['file_id'],
                    'original_name' => $result['file_name'],
                    'display_name' => $result['file_name'],
                    'size' => $result['file_size'],
                    'mime_type' => $result['mime_type'],
                    'caption' => $caption,
                    'date' => time(),
                    'uploaded_by' => $_SESSION['username'],
                    'upload_time' => time()
                ];
                
                $upload_success = "File '" . htmlspecialchars($result['file_name']) . "' berhasil diupload!";
            } else {
                $upload_error = "Gagal mengupload file: " . ($result['error'] ?? 'Unknown error');
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
    $filename = $_GET['filename'] ?? 'file';
    
    $file_url = getFileUrl($file_id);
    
    if ($file_url) {
        // Set headers untuk download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Stream file dari Telegram
        $ch = curl_init($file_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        $file_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            echo $file_content;
        } else {
            echo "Error downloading file";
        }
        exit;
    } else {
        $download_error = "File tidak ditemukan";
    }
}

// ============================================
// HANDLE RENAME FILE
// ============================================
if (isset($_POST['rename']) && isset($_SESSION['loggedin'])) {
    $file_id = $_POST['file_id'] ?? '';
    $new_name = sanitizeFilename($_POST['new_name'] ?? '');
    $old_name = $_POST['old_name'] ?? '';
    
    if (!empty($file_id) && !empty($new_name)) {
        // Simpan mapping rename
        $_SESSION['storage']['renamed'][$file_id] = $new_name;
        
        // Update nama di storage files
        foreach ($_SESSION['storage']['files'] as &$stored_file) {
            if ($stored_file['file_id'] === $file_id) {
                $stored_file['display_name'] = $new_name;
                break;
            }
        }
        
        $rename_success = "File '" . htmlspecialchars($old_name) . "' berhasil diubah menjadi '" . htmlspecialchars($new_name) . "'";
    }
}

// ============================================
// HANDLE SHARE FILE
// ============================================
if (isset($_POST['share']) && isset($_SESSION['loggedin'])) {
    $file_id = $_POST['file_id'] ?? '';
    $filename = $_POST['filename'] ?? '';
    
    if (!empty($file_id) && !empty($filename)) {
        // Generate share token
        $share_token = bin2hex(random_bytes(16));
        
        $_SESSION['storage']['shares'][$share_token] = [
            'file_id' => $file_id,
            'filename' => $filename,
            'created' => time(),
            'created_by' => $_SESSION['username']
        ];
        
        $share_url = $base_url . "?share=" . $share_token;
        $share_success = "Link sharing berhasil dibuat!";
        $share_link = $share_url;
    }
}

// ============================================
// HANDLE SHARED LINK ACCESS
// ============================================
if (isset($_GET['share'])) {
    $token = $_GET['share'] ?? '';
    
    if (isset($_SESSION['storage']['shares'][$token])) {
        $file_data = $_SESSION['storage']['shares'][$token];
        
        $file_url = getFileUrl($file_data['file_id']);
        
        if ($file_url) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file_data['filename']) . '"');
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
// HANDLE DELETE FILE (Local storage only)
// ============================================
if (isset($_GET['delete']) && isset($_SESSION['loggedin'])) {
    $file_id = $_GET['file_id'] ?? '';
    
    if (!empty($file_id)) {
        // Hapus dari storage
        foreach ($_SESSION['storage']['files'] as $key => $file) {
            if ($file['file_id'] === $file_id) {
                unset($_SESSION['storage']['files'][$key]);
                break;
            }
        }
        
        // Hapus rename mapping jika ada
        if (isset($_SESSION['storage']['renamed'][$file_id])) {
            unset($_SESSION['storage']['renamed'][$file_id]);
        }
        
        // Hapus share tokens yang terkait
        foreach ($_SESSION['storage']['shares'] as $token => $share) {
            if ($share['file_id'] === $file_id) {
                unset($_SESSION['storage']['shares'][$token]);
            }
        }
        
        $delete_success = "File berhasil dihapus dari storage lokal";
    }
}

// ============================================
// LOAD FILES
// ============================================
$all_files = [];

if (isset($_SESSION['loggedin'])) {
    // Ambil dari storage session
    foreach ($_SESSION['storage']['files'] as $file) {
        $display_name = $_SESSION['storage']['renamed'][$file['file_id']] ?? $file['display_name'];
        
        $all_files[] = [
            'file_id' => $file['file_id'],
            'display_name' => $display_name,
            'size' => $file['size'],
            'mime_type' => $file['mime_type'],
            'caption' => $file['caption'],
            'date' => $file['date'],
            'uploaded_by' => $file['uploaded_by'],
            'upload_time' => $file['upload_time']
        ];
    }
    
    // Juga coba ambil dari Telegram (background process)
    if (empty($all_files)) {
        $telegram_files = getFilesFromTelegram();
        $all_files = array_merge($all_files, $telegram_files);
    }
    
    // Urutkan berdasarkan tanggal terbaru
    usort($all_files, function($a, $b) {
        return ($b['date'] ?? 0) - ($a['date'] ?? 0);
    });
}

// ============================================
// CLEAR STORAGE
// ============================================
if (isset($_GET['clear_storage']) && isset($_SESSION['loggedin'])) {
    $_SESSION['storage']['files'] = [];
    $_SESSION['storage']['renamed'] = [];
    $_SESSION['storage']['shares'] = [];
    header("Location: " . $base_url);
    exit;
}

// ============================================
// REFRESH FILES FROM TELEGRAM
// ============================================
if (isset($_GET['refresh']) && isset($_SESSION['loggedin'])) {
    getFilesFromTelegram();
    header("Location: " . $base_url);
    exit;
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
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
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
            color: var(--accent-color);
            margin-bottom: 15px;
        }
        
        /* Main App */
        .app-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            margin: 20px auto;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 1400px;
        }
        
        /* Header */
        .app-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            border-bottom: 3px solid var(--accent-color);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
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
            animation: slideInRight 0.3s ease;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
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
            border-radius: 15px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .file-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
            border-color: var(--accent-color);
        }
        
        .file-icon {
            font-size: 2.5rem;
            color: var(--accent-color);
            margin-right: 15px;
        }
        
        .file-info {
            padding: 20px;
        }
        
        .file-name {
            font-weight: 600;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .file-meta {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            flex: 1;
            min-width: 100px;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        
        /* Upload Zone */
        .upload-zone {
            border: 3px dashed #ddd;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .upload-zone:hover {
            border-color: var(--accent-color);
            background: #e3f2fd;
        }
        
        /* Progress Bar */
        .progress {
            height: 10px;
            border-radius: 5px;
            margin: 20px 0;
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
        
        /* File Type Badges */
        .badge-type {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .badge-image { background: #e3f2fd; color: #1976d2; }
        .badge-video { background: #fce4ec; color: #c2185b; }
        .badge-audio { background: #f3e5f5; color: #7b1fa2; }
        .badge-pdf { background: #ffebee; color: #d32f2f; }
        .badge-other { background: #f5f5f5; color: #616161; }
        
        /* File ID Display */
        .file-id {
            font-family: monospace;
            font-size: 0.8rem;
            background: #f5f5f5;
            padding: 2px 5px;
            border-radius: 3px;
            word-break: break-all;
            margin-top: 5px;
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
            
            <?php if (isset($share_error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($share_error); ?>
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
        </div>
        
        <div class="app-container">
            <!-- Header -->
            <div class="app-header">
                <div class="header-content">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-cloud me-2"></i>OSIS Cloud Storage
                        </h1>
                        <small>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</small>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button class="btn btn-light" onclick="showUploadModal()">
                            <i class="fas fa-upload me-2"></i>Upload
                        </button>
                        
                        <a href="?refresh=1" class="btn btn-light">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </a>
                        
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="showUploadModal()">
                                    <i class="fas fa-upload me-2"></i>Upload File
                                </a></li>
                                <li><a class="dropdown-item" href="?refresh=1">
                                    <i class="fas fa-sync-alt me-2"></i>Refresh Files
                                </a></li>
                                <li><a class="dropdown-item" href="#" onclick="showStorageInfo()">
                                    <i class="fas fa-database me-2"></i>Storage Info
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
            
            <!-- Main Content -->
            <div class="container-fluid p-4">
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
                
                <!-- File Count -->
                <div class="mb-3">
                    <small class="text-muted">
                        <?php echo count($all_files); ?> files | 
                        <?php echo count($_SESSION['storage']['files'] ?? []); ?> in storage
                    </small>
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
                        </div>
                    <?php else: ?>
                        <div class="file-grid">
                            <?php foreach ($all_files as $file): ?>
                                <?php
                                $fileIcon = getFileIcon($file['display_name']);
                                $fileType = getFileType($file['display_name']);
                                $uploadDate = date('d M Y H:i', $file['date']);
                                $fileSize = formatBytes($file['size']);
                                $shortFileId = substr($file['file_id'], 0, 15) . '...';
                                
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
                                    <div class="file-info">
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
                                                <?php if (!empty($file['caption'])): ?>
                                                    <div class="file-meta">
                                                        <i class="fas fa-comment me-1"></i>
                                                        <?php echo htmlspecialchars(substr($file['caption'], 0, 50)); ?>
                                                        <?php if (strlen($file['caption']) > 50): ?>...<?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="file-id">
                                                    ID: <?php echo $shortFileId; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="file-actions">
                                            <a href="?download=1&file_id=<?php echo urlencode($file['file_id']); ?>&filename=<?php echo urlencode($file['display_name']); ?>" 
                                               class="btn btn-outline-primary btn-action">
                                                <i class="fas fa-download me-1"></i>Download
                                            </a>
                                            
                                            <button class="btn btn-outline-secondary btn-action" 
                                                    onclick="showRenameModal('<?php echo $file['file_id']; ?>', '<?php echo addslashes($file['display_name']); ?>')">
                                                <i class="fas fa-edit me-1"></i>Rename
                                            </button>
                                            
                                            <button class="btn btn-outline-info btn-action" 
                                                    onclick="showShareModal('<?php echo $file['file_id']; ?>', '<?php echo addslashes($file['display_name']); ?>')">
                                                <i class="fas fa-share-alt me-1"></i>Share
                                            </button>
                                        </div>
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
                            
                            <div class="progress d-none" id="uploadProgress">
                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="upload" class="btn btn-primary" id="uploadBtn" disabled>
                                <i class="fas fa-upload me-2"></i>Upload
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
                            <input type="hidden" name="old_name" id="renameOldName">
                            
                            <div class="mb-3">
                                <label class="form-label">Current Name</label>
                                <input type="text" class="form-control" id="currentNameDisplay" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">New Name</label>
                                <input type="text" class="form-control" name="new_name" id="newFileName" required>
                                <small class="text-muted">Include file extension (e.g., .jpg, .pdf)</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="rename" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save
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
                            <input type="hidden" name="filename" id="shareFileName">
                            
                            <div class="mb-3">
                                <label class="form-label">File to Share</label>
                                <input type="text" class="form-control" id="shareFileDisplay" readonly>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Anyone with the share link can download this file.
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
        
        <!-- Storage Info Modal -->
        <div class="modal fade" id="storageModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-database me-2"></i>Storage Information
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Local Storage</h6>
                                        <p class="mb-1">Files: <?php echo count($_SESSION['storage']['files'] ?? []); ?></p>
                                        <p class="mb-1">Renamed: <?php echo count($_SESSION['storage']['renamed'] ?? []); ?></p>
                                        <p class="mb-0">Share links: <?php echo count($_SESSION['storage']['shares'] ?? []); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Session Info</h6>
                                        <p class="mb-1">User: <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                                        <p class="mb-1">Login time: <?php echo date('H:i:s', $_SESSION['login_time'] ?? time()); ?></p>
                                        <p class="mb-0">Session ID: <?php echo session_id(); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($_SESSION['storage']['files'])): ?>
                            <h6>Recent Files:</h6>
                            <div class="list-group">
                                <?php 
                                $recentFiles = array_slice($_SESSION['storage']['files'], -3);
                                foreach ($recentFiles as $file): 
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <div class="text-truncate">
                                            <?php echo htmlspecialchars($file['display_name']); ?>
                                        </div>
                                        <div class="text-muted">
                                            <?php echo formatBytes($file['size']); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <a href="?clear_storage=1" class="btn btn-danger" onclick="return confirm('Clear all local storage?')">
                            <i class="fas fa-trash me-2"></i>Clear Storage
                        </a>
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
            const storageModal = new bootstrap.Modal(document.getElementById('storageModal'));
            
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
                document.getElementById('renameOldName').value = fileName;
                document.getElementById('currentNameDisplay').value = fileName;
                document.getElementById('newFileName').value = fileName;
                renameModal.show();
            }
            
            function showShareModal(fileId, fileName) {
                document.getElementById('shareFileId').value = fileId;
                document.getElementById('shareFileName').value = fileName;
                document.getElementById('shareFileDisplay').value = fileName;
                shareModal.show();
            }
            
            function showStorageInfo() {
                storageModal.show();
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
            
            // Progress bar simulation for large files
            document.querySelector('form')?.addEventListener('submit', function(e) {
                const fileInput = document.getElementById('fileInput');
                if (fileInput?.files[0]?.size > 50 * 1024 * 1024) { // 50MB
                    e.preventDefault();
                    
                    const progressBar = document.getElementById('uploadProgress');
                    const uploadBtn = document.getElementById('uploadBtn');
                    
                    progressBar.classList.remove('d-none');
                    uploadBtn.disabled = true;
                    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
                    
                    // Simulate progress
                    let progress = 0;
                    const interval = setInterval(() => {
                        progress += 5;
                        progressBar.querySelector('.progress-bar').style.width = progress + '%';
                        
                        if (progress >= 95) {
                            clearInterval(interval);
                            // Submit form setelah progress selesai
                            setTimeout(() => {
                                this.submit();
                            }, 1000);
                        }
                    }, 300);
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
