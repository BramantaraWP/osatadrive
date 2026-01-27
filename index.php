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
define('CHUNK_SIZE', 15 * 1024 * 1024); // 15MB per chunk
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('DATA_FILE', 'storage.json');
define('PARTS_FILE', 'file_parts.json');

// Base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

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
        'jpg' => 'fa-image', 'jpeg' => 'fa-image', 'png' => 'fa-image', 'gif' => 'fa-image',
        'mp4' => 'fa-video', 'avi' => 'fa-video', 'mov' => 'fa-video', 'mkv' => 'fa-video',
        'mp3' => 'fa-music', 'wav' => 'fa-music', 'ogg' => 'fa-music', 'm4a' => 'fa-music',
        'pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive'
    ];
    return isset($icons[$ext]) ? $icons[$ext] : 'fa-file';
}

function getFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) return 'image';
    if (in_array($ext, ['mp4', 'avi', 'mov', 'mkv'])) return 'video';
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) return 'audio';
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
// FUNGSI STORAGE JSON
// ============================================
function loadJSON($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function saveJSON($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function addFileToStorage($file_data) {
    $storage = loadJSON(DATA_FILE);
    $storage[] = $file_data;
    saveJSON(DATA_FILE, $storage);
    return count($storage) - 1; // Return index
}

function updateFileInStorage($index, $file_data) {
    $storage = loadJSON(DATA_FILE);
    if (isset($storage[$index])) {
        $storage[$index] = $file_data;
        saveJSON(DATA_FILE, $storage);
        return true;
    }
    return false;
}

function deleteFileFromStorage($index) {
    $storage = loadJSON(DATA_FILE);
    if (isset($storage[$index])) {
        array_splice($storage, $index, 1);
        saveJSON(DATA_FILE, $storage);
        return true;
    }
    return false;
}

function getAllFiles() {
    return loadJSON(DATA_FILE);
}

// ============================================
// FUNGSI FILE PARTS (Untuk chunking)
// ============================================
function saveFileParts($filename, $parts) {
    $parts_data = loadJSON(PARTS_FILE);
    $parts_data[$filename] = $parts;
    saveJSON(PARTS_FILE, $parts_data);
}

function getFileParts($filename) {
    $parts_data = loadJSON(PARTS_FILE);
    return $parts_data[$filename] ?? [];
}

function deleteFileParts($filename) {
    $parts_data = loadJSON(PARTS_FILE);
    if (isset($parts_data[$filename])) {
        unset($parts_data[$filename]);
        saveJSON(PARTS_FILE, $parts_data);
        return true;
    }
    return false;
}

// ============================================
// TELEGRAM API FUNCTIONS (Dengan Auto Caption JSON)
// ============================================
function telegramRequest($method, $params = []) {
    $url = API_URL . BOT_TOKEN . '/' . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
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
        return ['ok' => false, 'error' => $error];
    }
    
    $data = json_decode($response, true);
    return $data;
}

// Fungsi untuk mendapatkan semua file dari Telegram menggunakan getUpdates
function getFilesFromTelegram() {
    $result = telegramRequest('getUpdates', [
        'offset' => -100,
        'limit' => 100,
        'timeout' => 30
    ]);
    
    $files = [];
    
    if ($result['ok']) {
        foreach ($result['result'] as $update) {
            if (isset($update['channel_post']['document'])) {
                $post = $update['channel_post'];
                $doc = $post['document'];
                
                // Parse caption JSON jika ada
                $caption_data = ['filename' => $doc['file_name'] ?? 'Unknown'];
                if (!empty($post['caption'])) {
                    $parsed = json_decode($post['caption'], true);
                    if ($parsed) {
                        $caption_data = $parsed;
                    }
                }
                
                $files[] = [
                    'file_id' => $doc['file_id'],
                    'filename' => $caption_data['filename'],
                    'size' => $doc['file_size'] ?? 0,
                    'mime_type' => $doc['mime_type'] ?? 'application/octet-stream',
                    'caption' => $post['caption'] ?? '',
                    'date' => $post['date'],
                    'part' => $caption_data['part'] ?? null,
                    'total_parts' => $caption_data['total_parts'] ?? null,
                    'original_name' => $caption_data['original_name'] ?? $caption_data['filename']
                ];
            }
        }
    }
    
    return $files;
}

// Upload dengan chunking dan auto caption JSON
function uploadToTelegramWithChunks($file_path, $filename) {
    $file_size = filesize($file_path);
    
    // Jika file <= CHUNK_SIZE, upload langsung
    if ($file_size <= CHUNK_SIZE) {
        $cfile = new CURLFile($file_path, mime_content_type($file_path), $filename);
        
        // Auto caption dalam format JSON
        $caption = json_encode([
            'filename' => $filename,
            'original_name' => $filename,
            'part' => 1,
            'total_parts' => 1,
            'upload_time' => time()
        ], JSON_UNESCAPED_UNICODE);
        
        $params = [
            'chat_id' => CHAT_ID,
            'document' => $cfile,
            'caption' => $caption
        ];
        
        $result = telegramRequest('sendDocument', $params);
        
        if ($result['ok'] && isset($result['result']['document'])) {
            return [
                'success' => true,
                'parts' => [[
                    'file_id' => $result['result']['document']['file_id'],
                    'part' => 1,
                    'total_parts' => 1
                ]],
                'message_id' => $result['result']['message_id']
            ];
        }
        return ['success' => false, 'error' => $result['error'] ?? 'Upload failed'];
    }
    
    // Jika file besar, pecah menjadi chunk
    $total_parts = ceil($file_size / CHUNK_SIZE);
    $parts = [];
    
    $handle = fopen($file_path, 'rb');
    if (!$handle) {
        return ['success' => false, 'error' => 'Cannot open file'];
    }
    
    for ($i = 0; $i < $total_parts; $i++) {
        $offset = $i * CHUNK_SIZE;
        $chunk_data = fread($handle, CHUNK_SIZE);
        
        // Simpan chunk ke temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'chunk_');
        file_put_contents($temp_file, $chunk_data);
        
        $cfile = new CURLFile($temp_file, 'application/octet-stream', "{$filename}.part" . ($i + 1));
        
        // Auto caption dengan informasi part
        $caption = json_encode([
            'filename' => $filename,
            'original_name' => $filename,
            'part' => $i + 1,
            'total_parts' => $total_parts,
            'upload_time' => time()
        ], JSON_UNESCAPED_UNICODE);
        
        $params = [
            'chat_id' => CHAT_ID,
            'document' => $cfile,
            'caption' => $caption
        ];
        
        $result = telegramRequest('sendDocument', $params);
        
        // Hapus temporary file
        unlink($temp_file);
        
        if (!$result['ok']) {
            fclose($handle);
            return ['success' => false, 'error' => "Chunk " . ($i + 1) . " failed: " . ($result['error'] ?? 'Unknown')];
        }
        
        $parts[] = [
            'file_id' => $result['result']['document']['file_id'],
            'part' => $i + 1,
            'total_parts' => $total_parts,
            'message_id' => $result['result']['message_id']
        ];
        
        // Update progress (bisa digunakan untuk progress bar)
        $_SESSION['upload_progress'] = round(($i + 1) / $total_parts * 100);
    }
    
    fclose($handle);
    
    // Simpan informasi parts
    saveFileParts($filename, $parts);
    
    return [
        'success' => true,
        'parts' => $parts,
        'total_parts' => $total_parts
    ];
}

// Gabungkan chunk saat download
function downloadChunkedFile($filename) {
    $parts = getFileParts($filename);
    
    if (empty($parts)) {
        // Coba cari dari Telegram
        $telegram_files = getFilesFromTelegram();
        $file_parts = [];
        
        foreach ($telegram_files as $file) {
            if (!empty($file['caption'])) {
                $caption_data = json_decode($file['caption'], true);
                if ($caption_data && ($caption_data['filename'] == $filename || $caption_data['original_name'] == $filename)) {
                    $file_parts[] = [
                        'file_id' => $file['file_id'],
                        'part' => $caption_data['part'] ?? 1,
                        'total_parts' => $caption_data['total_parts'] ?? 1
                    ];
                }
            }
        }
        
        // Urutkan berdasarkan part
        usort($file_parts, function($a, $b) {
            return $a['part'] - $b['part'];
        });
        
        $parts = $file_parts;
    }
    
    if (empty($parts)) {
        return false;
    }
    
    // Jika hanya 1 part, langsung download
    if (count($parts) == 1) {
        return downloadSingleFile($parts[0]['file_id']);
    }
    
    // Jika multiple parts, gabungkan
    $combined_content = '';
    
    foreach ($parts as $part) {
        $file_content = downloadSingleFile($part['file_id']);
        if ($file_content === false) {
            return false;
        }
        $combined_content .= $file_content;
    }
    
    return $combined_content;
}

function downloadSingleFile($file_id) {
    $result = telegramRequest('getFile', ['file_id' => $file_id]);
    
    if ($result['ok']) {
        $file_path = $result['result']['file_path'];
        $telegram_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;
        
        $ch = curl_init($telegram_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $content = curl_exec($ch);
        curl_close($ch);
        
        return $content;
    }
    
    return false;
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
// SISTEM LOGIN
// ============================================
$users = [
    'admin' => password_hash('admin123', PASSWORD_DEFAULT),
    'osis' => password_hash('osis2024', PASSWORD_DEFAULT),
    'user' => password_hash('user123', PASSWORD_DEFAULT)
];

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
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
// HANDLE UPLOAD FILE (One-click)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileupload']) && isset($_SESSION['loggedin'])) {
    $file = $_FILES['fileupload'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        if ($file['size'] > MAX_FILE_SIZE) {
            $upload_error = "File terlalu besar (maksimal 2GB)";
        } else {
            // Upload ke Telegram dengan chunking
            $result = uploadToTelegramWithChunks($file['tmp_name'], $file['name']);
            
            if ($result['success']) {
                // Simpan ke storage JSON
                $file_data = [
                    'filename' => $file['name'],
                    'size' => $file['size'],
                    'mime_type' => $file['type'],
                    'uploaded_by' => $_SESSION['username'],
                    'upload_time' => time(),
                    'parts' => $result['parts'] ?? [],
                    'total_parts' => $result['total_parts'] ?? 1,
                    'message_id' => $result['message_id'] ?? null
                ];
                
                addFileToStorage($file_data);
                $upload_success = "File '" . htmlspecialchars($file['name']) . "' berhasil diupload!";
            } else {
                $upload_error = "Gagal mengupload file: " . ($result['error'] ?? 'Unknown error');
            }
        }
    } else {
        $upload_error = "Error upload: " . $file['error'];
    }
}

// ============================================
// HANDLE DOWNLOAD FILE
// ============================================
if (isset($_GET['download']) && isset($_SESSION['loggedin'])) {
    $filename = $_GET['filename'] ?? '';
    
    if (!empty($filename)) {
        $content = downloadChunkedFile($filename);
        
        if ($content !== false) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . strlen($content));
            
            echo $content;
            exit;
        }
    }
    $download_error = "File tidak ditemukan";
}

// ============================================
// HANDLE DELETE FILE
// ============================================
if (isset($_GET['delete']) && isset($_SESSION['loggedin'])) {
    $index = $_GET['index'] ?? '';
    $filename = $_GET['filename'] ?? '';
    
    if (!empty($index)) {
        // Hapus dari storage
        if (deleteFileFromStorage($index)) {
            $delete_success = "File berhasil dihapus dari storage";
        }
    }
    
    if (!empty($filename)) {
        // Hapus parts information
        deleteFileParts($filename);
    }
}

// ============================================
// HANDLE SHARE FILE
// ============================================
if (isset($_POST['share']) && isset($_SESSION['loggedin'])) {
    $filename = $_POST['filename'] ?? '';
    
    if (!empty($filename)) {
        // Generate share token
        $share_token = bin2hex(random_bytes(16));
        
        // Simpan di session (atau bisa di JSON terpisah)
        $_SESSION['share_tokens'][$share_token] = [
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
    
    if (isset($_SESSION['share_tokens'][$token])) {
        $file_data = $_SESSION['share_tokens'][$token];
        $filename = $file_data['filename'];
        
        $content = downloadChunkedFile($filename);
        
        if ($content !== false) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Content-Transfer-Encoding: binary');
            
            echo $content;
            exit;
        }
    } else {
        $share_error = "Link sharing tidak valid atau sudah kadaluarsa";
    }
}

// ============================================
// LOAD FILES (Dari Telegram API + Storage JSON)
// ============================================
$all_files = [];
if (isset($_SESSION['loggedin'])) {
    // Ambil dari storage JSON
    $all_files = getAllFiles();
    
    // Juga ambil dari Telegram API untuk memastikan data lengkap
    $telegram_files = getFilesFromTelegram();
    
    // Gabungkan dan group berdasarkan filename
    $grouped_files = [];
    
    foreach ($telegram_files as $file) {
        $filename = $file['original_name'] ?? $file['filename'];
        
        if (!isset($grouped_files[$filename])) {
            $grouped_files[$filename] = [
                'filename' => $filename,
                'size' => $file['size'],
                'mime_type' => $file['mime_type'],
                'date' => $file['date'],
                'parts' => [],
                'uploaded_by' => 'Telegram',
                'total_parts' => $file['total_parts'] ?? 1
            ];
        }
        
        if ($file['part']) {
            $grouped_files[$filename]['parts'][] = [
                'part' => $file['part'],
                'file_id' => $file['file_id']
            ];
        }
    }
    
    // Tambahkan dari storage JSON jika belum ada
    foreach ($all_files as $file) {
        if (!isset($grouped_files[$file['filename']])) {
            $grouped_files[$file['filename']] = $file;
        }
    }
    
    // Konversi ke array untuk ditampilkan
    $all_files = array_values($grouped_files);
    
    // Urutkan berdasarkan tanggal
    usort($all_files, function($a, $b) {
        return ($b['date'] ?? 0) - ($a['date'] ?? 0);
    });
}

// Hitung statistik
$stats = [
    'total_files' => count($all_files),
    'total_size' => 0,
    'by_type' => []
];

foreach ($all_files as $file) {
    $stats['total_size'] += $file['size'] ?? 0;
    $type = getFileType($file['filename']);
    if (!isset($stats['by_type'][$type])) {
        $stats['by_type'][$type] = 0;
    }
    $stats['by_type'][$type]++;
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
        .upload-container {
            padding: 20px;
            text-align: center;
        }
        
        .upload-box {
            display: inline-block;
            position: relative;
        }
        
        .upload-input {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        .upload-btn {
            background: linear-gradient(135deg, var(--accent) 0%, var(--primary) 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 1.1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
        }
        
        .upload-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
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
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .file-card:hover {
            border-color: var(--accent);
            box-shadow: 0 5px 15px rgba(57, 73, 171, 0.2);
            transform: translateY(-5px);
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
            gap: 8px;
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
            padding: 5px 10px;
        }
        
        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 5px solid var(--accent);
        }
        
        .stat-icon {
            font-size: 2rem;
            color: var(--accent);
            margin-bottom: 10px;
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
        
        /* Alerts */
        .alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        /* Progress Bar */
        .progress {
            height: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        
        /* Badges */
        .badge-part {
            background: #e3f2fd;
            color: #1976d2;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .badge-chunked {
            background: #fff3e0;
            color: #f57c00;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        @media (max-width: 768px) {
            .file-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .upload-btn {
                width: 100%;
                justify-content: center;
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
                        <form method="POST" action="" enctype="multipart/form-data" id="uploadForm" class="upload-box">
                            <input type="file" name="fileupload" id="fileInput" class="upload-input" required>
                            <button type="button" class="btn btn-light upload-btn" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-plus me-2"></i>Add File
                            </button>
                        </form>
                        
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="showStats()">
                                    <i class="fas fa-chart-bar me-2"></i>Statistics
                                </a></li>
                                <li><a class="dropdown-item" href="#" onclick="refreshFiles()">
                                    <i class="fas fa-sync-alt me-2"></i>Refresh Files
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
            
            <!-- Upload Progress -->
            <div class="upload-container" id="uploadProgressContainer" style="display: none;">
                <div class="progress">
                    <div class="progress-bar" id="uploadProgressBar" style="width: 0%"></div>
                </div>
                <p id="uploadProgressText" class="text-muted mt-2">Preparing upload...</p>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file"></i>
                    </div>
                    <h3><?php echo $stats['total_files']; ?></h3>
                    <p class="text-muted mb-0">Total Files</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-hdd"></i>
                    </div>
                    <h3><?php echo formatBytes($stats['total_size']); ?></h3>
                    <p class="text-muted mb-0">Total Size</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <h3><?php echo CHUNK_SIZE / 1024 / 1024; ?>MB</h3>
                    <p class="text-muted mb-0">Chunk Size</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-database"></i>
                    </div>
                    <h3>JSON</h3>
                    <p class="text-muted mb-0">Storage Type</p>
                </div>
            </div>
            
            <!-- Files Grid -->
            <div class="file-grid" id="filesGrid">
                <?php if (empty($all_files)): ?>
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-cloud-upload-alt fa-4x text-muted mb-3"></i>
                        <h3>No files yet</h3>
                        <p class="text-muted mb-4">Select a file to upload!</p>
                        <button class="btn btn-primary" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-plus me-2"></i>Add File
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_files as $index => $file): ?>
                        <?php
                        $fileIcon = getFileIcon($file['filename']);
                        $fileType = getFileType($file['filename']);
                        $uploadDate = isset($file['date']) ? date('d M Y H:i', $file['date']) : 
                                    (isset($file['upload_time']) ? date('d M Y H:i', $file['upload_time']) : 'Unknown');
                        $fileSize = formatBytes($file['size'] ?? 0);
                        $canPreview = canPreview($file['filename']);
                        $previewType = getPreviewType($file['filename']);
                        $isChunked = ($file['total_parts'] ?? 1) > 1;
                        ?>
                        
                        <div class="file-card" 
                             data-index="<?php echo $index; ?>"
                             data-filename="<?php echo htmlspecialchars($file['filename']); ?>"
                             data-file-type="<?php echo $previewType; ?>"
                             data-can-preview="<?php echo $canPreview ? '1' : '0'; ?>">
                            <div class="d-flex align-items-start mb-3">
                                <div class="file-icon">
                                    <i class="fas <?php echo $fileIcon; ?>"></i>
                                </div>
                                <div style="flex: 1;">
                                    <div class="file-name">
                                        <?php echo htmlspecialchars($file['filename']); ?>
                                        <?php if ($isChunked): ?>
                                            <span class="badge-chunked">
                                                <i class="fas fa-layer-group me-1"></i><?php echo $file['total_parts']; ?> parts
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="file-meta">
                                        <i class="fas fa-hdd me-1"></i> <?php echo $fileSize; ?>
                                    </div>
                                    <div class="file-meta">
                                        <i class="fas fa-calendar me-1"></i> <?php echo $uploadDate; ?>
                                    </div>
                                    <div class="file-meta">
                                        <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($file['uploaded_by'] ?? 'Unknown'); ?>
                                    </div>
                                    <?php if (isset($file['parts']) && count($file['parts']) > 0): ?>
                                        <div class="file-meta">
                                            <i class="fas fa-puzzle-piece me-1"></i>
                                            Parts: <?php echo count($file['parts']); ?>/<?php echo $file['total_parts']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="file-actions">
                                <a href="?download=1&filename=<?php echo urlencode($file['filename']); ?>" 
                                   class="btn btn-outline-primary btn-action">
                                    <i class="fas fa-download"></i>
                                </a>
                                
                                <button class="btn btn-outline-info btn-action" 
                                        onclick="shareFile('<?php echo urlencode($file['filename']); ?>')">
                                    <i class="fas fa-share-alt"></i>
                                </button>
                                
                                <button class="btn btn-outline-danger btn-action" 
                                        onclick="deleteFile(<?php echo $index; ?>, '<?php echo addslashes($file['filename']); ?>')">
                                    <i class="fas fa-trash"></i>
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
                        <div class="preview-content" id="previewContent">
                            <!-- Preview akan di-load di sini -->
                        </div>
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
        
        <!-- Statistics Modal -->
        <div class="modal fade" id="statsModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-chart-bar me-2"></i>Statistics
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6>Storage Info</h6>
                                        <p class="mb-1">Total Files: <strong><?php echo $stats['total_files']; ?></strong></p>
                                        <p class="mb-1">Total Size: <strong><?php echo formatBytes($stats['total_size']); ?></strong></p>
                                        <p class="mb-0">Chunk Size: <strong><?php echo formatBytes(CHUNK_SIZE); ?></strong></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6>User Info</h6>
                                        <p class="mb-1">Username: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></p>
                                        <p class="mb-1">Login Time: <strong><?php echo date('H:i:s', $_SESSION['login_time'] ?? time()); ?></strong></p>
                                        <p class="mb-0">Session: <strong><?php echo session_id(); ?></strong></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h6>Files by Type</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>File Type</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['by_type'] as $type => $count): ?>
                                    <tr>
                                        <td><?php echo ucfirst($type); ?></td>
                                        <td><?php echo $count; ?></td>
                                        <td>
                                            <?php 
                                                $percentage = $stats['total_files'] > 0 ? ($count / $stats['total_files']) * 100 : 0;
                                                echo number_format($percentage, 1) . '%';
                                            ?>
                                        </td>
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
                            <input type="hidden" name="filename" id="shareFileName">
                            <p>Generate a share link for this file. The link will be valid for this session.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="share" class="btn btn-primary">
                                <i class="fas fa-link me-2"></i>Generate Link
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <script>
            // Inisialisasi modal
            const previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
            const statsModal = new bootstrap.Modal(document.getElementById('statsModal'));
            const shareModal = new bootstrap.Modal(document.getElementById('shareModal'));
            
            // One-click upload
            document.getElementById('fileInput').addEventListener('change', function(e) {
                const file = this.files[0];
                if (file) {
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        alert('File too large (max 2GB)');
                        this.value = '';
                        return;
                    }
                    
                    // Tampilkan progress bar
                    document.getElementById('uploadProgressContainer').style.display = 'block';
                    const progressBar = document.getElementById('uploadProgressBar');
                    const progressText = document.getElementById('uploadProgressText');
                    
                    // Simulasikan progress untuk chunking
                    let progress = 0;
                    const chunkSize = <?php echo CHUNK_SIZE; ?>;
                    const totalChunks = Math.ceil(file.size / chunkSize);
                    
                    if (totalChunks > 1) {
                        progressText.textContent = `Splitting into ${totalChunks} chunks...`;
                    } else {
                        progressText.textContent = 'Uploading file...';
                    }
                    
                    const interval = setInterval(() => {
                        progress += 5;
                        progressBar.style.width = progress + '%';
                        
                        if (progress >= 95) {
                            clearInterval(interval);
                            progressText.textContent = 'Finalizing upload...';
                        }
                    }, 300);
                    
                    // Submit form
                    document.getElementById('uploadForm').submit();
                }
            });
            
            // Double click untuk preview
            document.querySelectorAll('.file-card').forEach(card => {
                card.addEventListener('dblclick', function() {
                    const canPreview = this.dataset.canPreview === '1';
                    const filename = this.dataset.filename;
                    const fileType = this.dataset.fileType;
                    
                    if (canPreview) {
                        showPreview(filename, fileType);
                    } else {
                        // Download jika tidak bisa preview
                        window.location.href = `?download=1&filename=${encodeURIComponent(filename)}`;
                    }
                });
            });
            
            // Preview function
            function showPreview(filename, fileType) {
                const previewUrl = `?download=1&filename=${encodeURIComponent(filename)}`;
                
                document.getElementById('previewTitle').textContent = filename;
                document.getElementById('previewDownloadBtn').href = previewUrl;
                
                const previewContent = document.getElementById('previewContent');
                previewContent.innerHTML = '';
                
                switch(fileType) {
                    case 'image':
                        previewContent.innerHTML = `
                            <img src="${previewUrl}" class="preview-image" alt="${filename}">
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
                            <p class="mt-2">${filename}</p>
                        `;
                        break;
                        
                    case 'pdf':
                        previewContent.innerHTML = `
                            <div class="pdf-viewer" id="pdfViewer"></div>
                            <p>PDF Preview (First Page)</p>
                        `;
                        
                        // Load PDF preview
                        setTimeout(() => {
                            pdfjsLib.getDocument(previewUrl).promise.then(pdf => {
                                pdf.getPage(1).then(page => {
                                    const canvas = document.createElement('canvas');
                                    const context = canvas.getContext('2d');
                                    const viewport = page.getViewport({ scale: 1.5 });
                                    
                                    canvas.height = viewport.height;
                                    canvas.width = viewport.width;
                                    
                                    const renderContext = {
                                        canvasContext: context,
                                        viewport: viewport
                                    };
                                    
                                    page.render(renderContext);
                                    document.getElementById('pdfViewer').appendChild(canvas);
                                });
                            });
                        }, 100);
                        break;
                }
                
                previewModal.show();
            }
            
            function showStats() {
                statsModal.show();
            }
            
            function refreshFiles() {
                window.location.reload();
            }
            
            function shareFile(filename) {
                document.getElementById('shareFileName').value = filename;
                shareModal.show();
            }
            
            function deleteFile(index, filename) {
                if (confirm(`Are you sure you want to delete "${filename}"?`)) {
                    window.location.href = `?delete=1&index=${index}&filename=${encodeURIComponent(filename)}`;
                }
            }
            
            // Auto-hide alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getInstance(alert)?.close();
                });
            }, 5000);
            
            // Drag and drop upload
            document.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
            });
            
            document.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const fileInput = document.getElementById('fileInput');
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(files[0]);
                    fileInput.files = dataTransfer.files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modals = document.querySelectorAll('.modal.show');
                    if (modals.length > 0) {
                        bootstrap.Modal.getInstance(modals[0])?.hide();
                    }
                }
                
                // Ctrl+F untuk focus search
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    // Bisa tambahkan search functionality
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
