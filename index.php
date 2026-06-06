<?php
session_start();

// --- REGENERACJA IDENTYFIKATORA SESJI (BEZPIECZEŃSTWO) ---
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// --- GENEROWANIE I WALIDACJA TOKENU CSRF ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['status' => 'error_csrf', 'message' => 'Kolizja tokenu CSRF. Żądanie odrzucone.']);
        exit;
    }
}

// --- GENEROWANIE GŁÓWNEGO KLUCZA SERWERA ---
$SECRET_FILE = __DIR__ . '/matrix_secret.key';
if (!file_exists($SECRET_FILE)) {
    file_put_contents($SECRET_FILE, bin2hex(random_bytes(32)));
}
$SERVER_SECRET = file_get_contents($SECRET_FILE);

// --- FUNKCJE KRYPTOGRAFICZNE (AES-256-CBC) ---
function get_master_crypto_key() {
    global $SERVER_SECRET;
    return hash('sha256', $SERVER_SECRET);
}

function matrix_encrypt($text) {
    $key = hex2bin(get_master_crypto_key());
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

function matrix_decrypt($encrypted_base64) {
    $key = hex2bin(get_master_crypto_key());
    $data = base64_decode($encrypted_base64);
    if (strlen($data) < 17) return '[BŁĄD DEKODOWANIA]';
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '[USZKODZONY PAKIET]';
}

// --- INICJALIZACJA BAZY SQLITE ---
$db = new PDO('sqlite:' . __DIR__ . '/matrix_database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password_hash TEXT,
    color TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS chat_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp TEXT,
    sender TEXT,
    color TEXT,
    encrypted_msg TEXT,
    target TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS online_status (
    username TEXT PRIMARY KEY,
    last_seen INTEGER
)");

// Autoczyszczenie starych rekordów (max 100 wpisów)
try {
    $db->exec("DELETE FROM chat_history WHERE id NOT IN (SELECT id FROM chat_history ORDER BY id DESC LIMIT 100)");
} catch(Exception $e) {}

if (isset($_SESSION['matrix_user'])) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO online_status (username, last_seen) VALUES (?, ?)");
    $stmt->execute([$_SESSION['matrix_user'], time()]);
}

// --- API MODUŁY ASYNCHRONICZNE ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    header('Content-Type: application/json');

    if ($action == 'get_online') {
        $cutoff = time() - 10;
        $db->prepare("DELETE FROM online_status WHERE last_seen < ?")->execute([$cutoff]);
        
        $stmt = $db->query("SELECT o.username, u.color FROM online_status o LEFT JOIN users u ON o.username = u.username");
        $list = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $list[] = [
                'name' => htmlspecialchars($row['username']),
                'color' => $row['color'] ? htmlspecialchars($row['color']) : '#aaaaaa'
            ];
        }
        echo json_encode($list);
        exit;
    }

    if ($action == 'get_messages') {
        $target = $_GET['target'] ?? 'global';
        
        $stmt = $db->query("SELECT * FROM chat_history ORDER BY id ASC");
        $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $messages = [];
        $invites = [];
        $invite_final_states = [];

        // 1. NAPRAWIONY HANDSHAKE PRIV
        foreach (array_reverse($all_rows) as $row) {
            $decrypted_msg = matrix_decrypt($row['encrypted_msg']);
            $msg_type = $row['target'];
            $db_sender = $row['sender'];

            if (in_array($msg_type, ['__SYS_INVITE__', '__SYS_ACCEPT__', '__SYS_DECLINE__'])) {
                if ($db_sender === $_SESSION['matrix_user']) {
                    $other_user = $decrypted_msg;
                    $am_i_sender = true;
                } elseif ($decrypted_msg === $_SESSION['matrix_user']) {
                    $other_user = $db_sender;
                    $am_i_sender = false;
                } else {
                    continue; 
                }

                if (!isset($invite_final_states[$other_user])) {
                    if ($msg_type === '__SYS_INVITE__' && !$am_i_sender) {
                        $invite_final_states[$other_user] = 'pending';
                    } elseif ($msg_type === '__SYS_ACCEPT__') {
                        $invite_final_states[$other_user] = 'accepted';
                    } elseif ($msg_type === '__SYS_DECLINE__') {
                        $invite_final_states[$other_user] = 'declined';
                    } else {
                        $invite_final_states[$other_user] = 'sent';
                    }
                }
            }
        }

        // 2. Renderowanie normalnych wiadomości
        foreach ($all_rows as $row) {
            $decrypted_msg = matrix_decrypt($row['encrypted_msg']);
            $msg_type = $row['target'];
            $sender = $row['sender'];

            if (in_array($msg_type, ['__SYS_INVITE__', '__SYS_ACCEPT__', '__SYS_DECLINE__'])) continue;

            $allowed = false;
            $is_priv = false;

            if ($target === 'global' && $msg_type === 'global') {
                $allowed = true;
            } else if ($target !== 'global' && $msg_type !== 'global') {
                if (in_array($sender, [$target, $_SESSION['matrix_user']]) && in_array($msg_type, [$target, $_SESSION['matrix_user']])) {
                    $allowed = true;
                    $is_priv = true;
                }
            }

            if ($allowed) {
                $messages[] = [
                    'id' => $row['id'],
                    'time' => htmlspecialchars($row['timestamp']),
                    'sender' => htmlspecialchars($sender),
                    'color' => htmlspecialchars($row['color'] ?? '#aaaaaa'),
                    'msg' => htmlspecialchars($decrypted_msg ?? ''),
                    'is_priv' => $is_priv
                ];
            }
        }

        foreach ($invite_final_states as $s => $state) {
            if ($state !== 'sent') {
                $invites[$s] = $state;
            }
        }

        echo json_encode(['messages' => array_slice($messages, -80), 'invites' => $invites]);
        exit;
    }

    if ($action == 'send_message' && isset($_SESSION['matrix_user'])) {
        verify_csrf();
        $msg = trim($_POST['message'] ?? '');
        $target = trim($_POST['target'] ?? 'global');

        if (!empty($msg) && strlen($msg) <= 500) {
            $enc_msg = matrix_encrypt($msg);
            $stmt = $db->prepare("INSERT INTO chat_history (timestamp, sender, color, encrypted_msg, target) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([date('H:i:s'), $_SESSION['matrix_user'], $_SESSION['matrix_color'], $enc_msg, $target]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'empty']);
        }
        exit;
    }

    if ($action == 'upload_file' && isset($_SESSION['matrix_user'])) {
        verify_csrf();
        $target = trim($_POST['target'] ?? 'global');

        if (!isset($_FILES['matrix_file']) || $_FILES['matrix_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error_upload']); exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($_FILES['matrix_file']['tmp_name']);
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'application/zip'];

        if (!in_array($mime_type, $allowed_mimes)) {
            echo json_encode(['status' => 'error_type']); exit;
        }

        $file_name = preg_replace("/[^a-zA-Z0-9\._\-]/", "_", $_FILES['matrix_file']['name']);
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $uploads_dir = __DIR__ . '/matrix_uploads';
        if (!file_exists($uploads_dir)) mkdir($uploads_dir, 0777, true);

        $new_name = 'PAK_' . md5(time() . rand()) . '.' . $file_ext;
        $dest_path = $uploads_dir . '/' . $new_name;

        if (move_uploaded_file($_FILES['matrix_file']['tmp_name'], $dest_path)) {
            $web_path = 'matrix_uploads/' . $new_name;
            $sys_msg = "::FILE_TAG::" . $web_path . "::" . $file_name . "::" . $file_ext;
            $enc_msg = matrix_encrypt($sys_msg);

            $stmt = $db->prepare("INSERT INTO chat_history (timestamp, sender, color, encrypted_msg, target) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([date('H:i:s'), $_SESSION['matrix_user'], $_SESSION['matrix_color'], $enc_msg, $target]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error_move']);
        }
        exit;
    }
}

// --- LOGOWANIE I REJESTRACJA ---
$error = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['form_type'])) {
    verify_csrf();
    $type = $_POST['form_type'];

    if ($type == 'guest') {
        $_SESSION['matrix_user'] = "GUEST_" . bin2hex(random_bytes(4));
        $_SESSION['matrix_color'] = '#aaaaaa';
        session_regenerate_id(true);
        header("Location: index.php"); exit;
    }

    $user = preg_replace("/[^a-zA-Z0-9_]/", "", $_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $chosen_color = $_POST['user_color'] ?? '#00ff41';

    if (!preg_match('/^#[a-fA-F0-9]{6}$/', $chosen_color)) {
        $chosen_color = '#00ff41';
    }

    if (empty($user) || empty($pass)) {
        $error = "Wprowadź kompletne klucze.";
    } else {
        if ($type == 'register') {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$user]);
            if ($stmt->fetch() || strpos($user, 'GUEST_') === 0) {
                $error = "Kryptonim zajęty w sieci.";
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, color) VALUES (?, ?, ?)");
                $stmt->execute([$user, $hash, $chosen_color]);

                $_SESSION['matrix_user'] = $user;
                $_SESSION['matrix_color'] = $chosen_color;
                session_regenerate_id(true);
                header("Location: index.php"); exit;
            }
        } elseif ($type == 'login') {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$user]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userData && password_verify($pass, $userData['password_hash'])) {
                $_SESSION['matrix_user'] = $user;
                $_SESSION['matrix_color'] = $userData['color'];
                session_regenerate_id(true);
                header("Location: index.php"); exit;
            } else {
                $error = "Błędne klucze autoryzacji sygnatury.";
            }
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] == 'change_color_live' && isset($_SESSION['matrix_user'])) {
    verify_csrf();
    $new_color = $_POST['color'] ?? '#00ff41';
    if (preg_match('/^#[a-fA-F0-9]{6}$/', $new_color)) {
        $_SESSION['matrix_color'] = $new_color;
        $stmt = $db->prepare("UPDATE users SET color = ? WHERE username = ?");
        $stmt->execute([$new_color, $_SESSION['matrix_user']]);
        echo "success";
    }
    exit;
}

if (isset($_GET['logout'])) {
    if (isset($_SESSION['matrix_user'])) {
        $db->prepare("DELETE FROM online_status WHERE username = ?")->execute([$_SESSION['matrix_user']]);
    }
    session_destroy();
    header("Location: index.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#000000">

    <title>Matrix Terminal APEX v6.0</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&display=swap');
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        /* ZABEZPIECZENIE PRZED UCINANIEM EKRANU I SCROLLOWANIEM BODY */
        body, html { 
            background-color: #000; color: #00ff41; font-family: 'Courier Prime', monospace; 
            height: 100dvh; width: 100vw; overflow: hidden; font-size: 14px; position: fixed; 
        }
        
        body::after { content: " "; display: block; position: fixed; top: 0; left: 0; bottom: 0; right: 0; background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%); z-index: 99990; background-size: 100% 3px; pointer-events: none; }
        #matrix-canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; opacity: 0.12; pointer-events: none; }
        
        .matrix-container { position: relative; z-index: 10; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; padding: 12px; }
        
        /* GŁÓWNY LAYOUT KONSOLI - WYPEŁNIA DOSTĘPNĄ PRZESTRZEŃ */
        .terminal-box { background: rgba(0, 5, 1, 0.96); border: 2px solid #00ff41; box-shadow: 0 0 20px rgba(0, 255, 65, 0.4); width: 100%; max-width: 1100px; height: 100%; display: flex; flex-direction: column; padding: 12px; transition: all 0.3s ease; }
        .terminal-box.priv-mode-active { border-color: #ff3333; background: rgba(12, 1, 1, 0.97); box-shadow: 0 0 25px rgba(255, 51, 51, 0.4); }
        
        .auth-box { max-width: 420px; height: auto; margin: auto; padding: 25px; background: rgba(0, 0, 0, 0.96); }
        h1 { text-transform: uppercase; letter-spacing: 2px; font-size: 1.4rem; margin-bottom: 15px; text-shadow: 0 0 8px #00ff41; text-align: center; }
        
        .header-info { border-bottom: 1px dashed #005f18; padding-bottom: 8px; margin-bottom: 12px; font-size: 0.8rem; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; flex-shrink: 0; }
        
        #system-alert-banner { display: none; background: rgba(30, 0, 0, 0.95); border: 1px dashed #ff3333; color: #ff3333; padding: 10px; margin-bottom: 10px; font-size: 0.85rem; font-weight: bold; text-align: center; box-shadow: 0 0 10px rgba(255,51,51,0.5); flex-shrink: 0; }
        .alert-btn { background: #ff3333; color: #000; border: none; padding: 4px 10px; font-family: 'Courier Prime', monospace; font-weight: bold; font-size: 0.8rem; margin: 4px; cursor: pointer; text-transform: uppercase; }
        .alert-btn.accept { background: #00ff41; }

        .chat-tabs { display: flex; gap: 5px; margin-bottom: 4px; overflow-x: auto; padding-bottom: 2px; flex-shrink: 0; }
        .tab { background: #001f05; border: 1px solid #005f18; border-bottom: none; color: #008f25; padding: 8px 14px; cursor: pointer; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; }
        .tab.active { background: rgba(0, 8, 2, 0.7); border-color: #00ff41; color: #00ff41; font-weight: bold; }
        .tab.active.tab-priv-style { border-color: #ff3333; color: #ff3333; background: #200000; }
        .tab .close-tab { color: #ff3333; margin-left: 8px; font-weight: bold; cursor: pointer; }

        .chat-layout { display: flex; flex-direction: row; gap: 12px; flex: 1; min-height: 0; }
        .chat-main { display: flex; flex-direction: column; flex: 3; min-height: 0; }
        
        /* OKNO CZATU - WYMUSZENIE PRZEWIJANIA WEWNĄTRZ */
        #chat-window { flex: 1; overflow-y: auto; border: 1px solid #005f18; padding: 12px; background: rgba(0, 8, 2, 0.7); margin-bottom: 8px; overscroll-behavior: contain; }
        
        .input-wrapper { display: flex; flex-direction: column; gap: 6px; width: 100%; flex-shrink: 0; }
        .input-group { display: flex; gap: 8px; width: 100%; align-items: stretch; }
        
        input[type="text"], input[type="password"], select { background: #000; border: 1px solid #00ff41; color: #00ff41; font-family: 'Courier Prime', monospace; padding: 10px; font-size: 1rem; outline: none; width: 100%; border-radius: 0; }
        #message-input { flex: 1; min-width: 0; }
        
        button { background: #00ff41; color: #000; border: none; padding: 10px 18px; font-family: 'Courier Prime', monospace; font-weight: bold; font-size: 0.95rem; cursor: pointer; text-transform: uppercase; border-radius: 0; display: flex; align-items: center; justify-content: center; }
        button:hover { background: #000; color: #00ff41; outline: 1px solid #00ff41; }
        
        .input-meta-info-line { display: flex; justify-content: space-between; align-items: center; padding: 0 4px; font-size: 0.8rem; color: #005f18; flex-wrap: wrap; gap: 4px; flex-shrink: 0; }
        #matrix-typing-status { color: #ccff00; font-weight: bold; display: none; text-transform: uppercase; }
        #upload-status-text { color: #ffcc00; display: none; }

        .chat-sidebar { flex: 1; border-left: 1px solid #005f18; padding-left: 12px; display: flex; flex-direction: column; min-height: 0; gap: 12px; }
        .panel-title { font-size: 0.8rem; text-transform: uppercase; border-bottom: 1px solid #005f18; padding-bottom: 4px; letter-spacing: 1px; font-weight: bold; margin-bottom: 6px; }
        #online-list { flex: 1; overflow-y: auto; }
        .online-user { margin-bottom: 6px; font-size: 0.9rem; display: flex; align-items: center; padding: 4px; transition: all 0.2s; }
        .online-user:hover { background: #001f05; }
        .online-dot { display: inline-block; width: 8px; height: 8px; background-color: #00ff41; border-radius: 50%; margin-right: 8px; box-shadow: 0 0 4px #00ff41; }
        
        .msg-line { margin-bottom: 8px; line-height: 1.4; font-size: 0.95rem; word-break: break-word; }
        .time-tag { color: #005f18; margin-right: 6px; font-size: 0.8rem; }
        .msg-text { color: #e1ffe6; }
        .system-msg { color: #008f25; font-style: italic; }
        .terminal-inline-alert { color: #ff3333; font-weight: bold; font-style: italic; animation: pulseAlert 1s infinite alternate; }
        @keyframes pulseAlert { from { opacity: 0.7; } to { opacity: 1; } }
        
        .matrix-uploaded-img { max-width: 100%; max-height: 180px; border: 1px solid #00ff41; margin-top: 6px; display: block; cursor: pointer; }
        
        /* SEKWENCJA STARTOWA */
        #boot-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 999999; display: flex; flex-direction: column; justify-content: center; align-items: center; font-size: 1.2rem; font-weight: bold; color: #00ff41; transition: opacity 0.4s ease; }
        
        /* 📱 KLUCZOWE REFORMY DLA SMARTFONÓW - BEZ UCINANIA */
        @media (max-width: 768px) {
            body, html { font-size: 13px; }
            .matrix-container { padding: 4px; }
            .terminal-box { padding: 8px; border-width: 1px; }
            .chat-layout { flex-direction: column; gap: 8px; }
            .chat-main { flex: 1; }
            .chat-sidebar { border-left: none; border-top: 1px dashed #005f18; padding-left: 0; padding-top: 8px; flex: none; height: 100px; flex-direction: row; }
            .sidebar-panel-section { flex: 1; display: flex; flex-direction: column; min-height: 0; }
            #online-list { flex: 1; }
            .input-group { gap: 4px; }
            input[type="text"] { padding: 12px 10px; font-size: 1rem; } 
            button { padding: 12px 14px; font-size: 0.9rem; }
        }
    </style>
    <script>const CSRF_TOKEN = "<?php echo $_SESSION['csrf_token']; ?>";</script>
</head>
<body>
    <canvas id="matrix-canvas"></canvas>

    <?php if (isset($_SESSION['matrix_user'])): ?>
        <div id="boot-overlay">
            <div id="boot-text">INITIALIZING CORE...</div>
        </div>
    <?php endif; ?>

    <div class="matrix-container">
        <?php if (!isset($_SESSION['matrix_user'])): ?>
            <div class="terminal-box auth-box" id="auth-panel">
                <h1>Dostęp do Rdzenia</h1>
                <?php if(!empty($error)): ?>
                    <div style="color:#ff3333; margin-bottom:12px; font-size:0.85rem; text-align:center; font-weight:bold;"><?= $error ?></div>
                <?php endif; ?>

                <form method="POST" action="index.php" style="margin-bottom: 12px;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="form_type" id="form_type" value="login">
                    <div style="margin-bottom: 12px;">
                        <input type="text" name="username" placeholder="KRYPTONIM" required autocomplete="off">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <input type="password" name="password" placeholder="KLUCZ SZYFRUJĄCY" required>
                    </div>
                    <div id="color-selector" style="display: none; margin-bottom: 12px;">
                        <select name="user_color">
                            <option value="#00ff41">Kod Maszyny (Klasyczny Zielony)</option>
                            <option value="#3399ff">Gaming Node (Niebieski Neon)</option>
                            <option value="#ff3333">Czerwona Pigułka (Czerwony)</option>
                            <option value="#33ffff">Sygnatura Neona (Cyan)</option>
                            <option value="#ff00ff">Wektor Trinity (Fuksja)</option>
                            <option value="#00ffaa">System Agentów (Miętowy)</option>
                            <option value="#ffaa00">Wyrocznia (Pomarańczowy)</option>
                            <option value="#ffffff">Architekt (Czysty Biały)</option>
                            <option value="#aaaaaa">Widmo (Zion Ghost)</option>
                            <option value="#ccff00">Program Banita (Limonkowy)</option>
                        </select>
                    </div>
                    <button type="submit" style="width: 100%;">Uruchom Łącze</button>
                </form>

                <form method="POST" action="index.php" style="margin-bottom: 12px;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="form_type" value="guest">
                    <button type="submit" style="width: 100%; background: #003a0f; color: #00ff41; border: 1px solid #00ff41;">Wejdź jako GOŚĆ</button>
                </form>
                <div style="text-align: center;"><span style="color:#008f25; cursor:pointer; text-decoration:underline; font-size:0.85rem;" id="toggle-auth" onclick="toggleAuth()">[ Utwórz profil ]</span></div>
            </div>
        <?php else: ?>
            <div id="matrix-main-terminal-frame" class="terminal-box">
                <div class="header-info">
                    <div>WĘZEŁ: <span style="color: <?= $_SESSION['matrix_color'] ?>; font-weight:bold; text-shadow: 0 0 5px <?= $_SESSION['matrix_color'] ?>;"><?= $_SESSION['matrix_user'] ?></span></div>
                    <div id="matrix-latency-display">LATENCY: -- | SECURE APEX v6.0</div>
                    <div>
                        <span style="color:#00ff41; cursor:pointer; font-weight:bold; margin-right: 15px;" onclick="toggleFullscreen()">[ PEŁNY EKRAN ]</span>
                        <a href="?logout=1" style="color: #ff3333; text-decoration: none; font-weight: bold;">[ ODŁĄCZ ]</a>
                    </div>
                </div>

                <div id="system-alert-banner">
                    <span id="system-alert-text">KOMUNIKAT SYSTEMOWY</span><br>
                    <button class="alert-btn accept" id="btn-accept-invite">POŁĄCZ</button>
                    <button class="alert-btn" id="btn-decline-invite">ZABLOKUJ</button>
                </div>

                <div class="chat-tabs" id="chat-tabs-container">
                    <div class="tab active" id="tab-global" onclick="switchChannel('global')">[GLOBAL]</div>
                </div>

                <div class="chat-layout">
                    <div class="chat-main">
                        <div id="chat-window"></div>
                        <form id="chat-form" onsubmit="sendMessage(event)">
                            <div class="input-wrapper">
                                <div class="input-group">
                                    <input type="text" id="message-input" placeholder="Komunikat... (/help)" autocomplete="off" maxlength="500" oninput="handleTypingEffect(this)">
                                    <button type="button" onclick="document.getElementById('hidden-file-input').click()" style="background:#005f18; color:#00ff41; border-right:1px solid #00ff41;">PLIK</button>
                                    <button type="submit">NADAJ</button>
                                </div>
                                <div class="input-meta-info-line">
                                    <div id="matrix-typing-status">[ ENKODOWANIE PAKIETU... ]</div>
                                    <div id="upload-status-text">[ STRUMIENIOWANIE PLIKU... ]</div>
                                    <div id="char-counter">0 / 500 p.</div>
                                </div>
                            </div>
                        </form>
                        <input type="file" id="hidden-file-input" style="display:none;" onchange="uploadMatrixFile(this)">
                    </div>

                    <div class="chat-sidebar">
                        <div class="sidebar-panel-section">
                            <div class="panel-title">Jednostki (priv)</div>
                            <div id="online-list"></div>
                        </div>
                        <div class="sidebar-panel-section">
                            <div class="panel-title">Barwa kodu</div>
                            <select id="live-color-picker" onchange="changeLiveColor(this.value)">
                                <option value="">Zmień</option>
                                <option value="#00ff41">Kod Maszyny</option>
                                <option value="#3399ff">Gaming Node (Neon Blue)</option>
                                <option value="#ff3333">Czerwona Pigułka</option>
                                <option value="#33ffff">Sygnatura Neona (Cyan)</option>
                                <option value="#ff00ff">Wektor Trinity</option>
                                <option value="#00ffaa">System Agentów</option>
                                <option value="#ffaa00">Wyrocznia</option>
                                <option value="#ffffff">Architekt</option>
                                <option value="#aaaaaa">Widmo</option>
                                <option value="#ccff00">Program Banita</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="matrix-footer" style="margin-top: 10px; border-top: 1px dashed #005f18; padding-top: 8px; font-size: 0.8rem; color: #005f18; text-align: center;">matrix czat by <a href="https://github.com/Swir" target="_blank" style="color:#00ff41; font-weight:bold; text-shadow:0 0 5px #00ff41; text-decoration:none;">Swir</a> | CORE ENGINE APEX</div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // --- BOOT SEQUENCE ---
        <?php if (isset($_SESSION['matrix_user'])): ?>
        window.addEventListener('load', () => {
            const overlay = document.getElementById('boot-overlay');
            if(overlay) {
                let steps = ["ESTABLISHING SECURE CONNECTION...", "NEGOTIATING AES-256 KEYS...", "BYPASSING FIREWALLS...", "ACCESS GRANTED."];
                let i = 0;
                let intv = setInterval(() => {
                    if(i < steps.length) {
                        document.getElementById('boot-text').innerText = steps[i];
                        i++;
                    } else {
                        clearInterval(intv);
                        overlay.style.opacity = "0";
                        setTimeout(() => overlay.remove(), 400);
                    }
                }, 300);
            }
        });
        <?php endif; ?>

        // --- NATYWNY SYNTEZATOR DŹWIĘKU ---
        document.addEventListener('click', () => {
            if(audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
        }, {once:true});

        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        
        function playTerminalBeep(freq = 600, duration = 0.03) {
            try {
                const osc = audioCtx.createOscillator(); const gainNode = audioCtx.createGain();
                osc.type = 'sine'; osc.frequency.value = freq; gainNode.gain.setValueAtTime(0.01, audioCtx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.00001, audioCtx.currentTime + duration);
                osc.connect(gainNode); gainNode.connect(audioCtx.destination);
                osc.start(); osc.stop(audioCtx.currentTime + duration);
            } catch(e){}
        }
        function playTerminalAlertSound() { playTerminalBeep(350, 0.15); setTimeout(() => playTerminalBeep(250, 0.2), 180); }
        
        function playMessageReceiveSound() {
            if(audioCtx.state === 'suspended') audioCtx.resume();
            try {
                const osc = audioCtx.createOscillator(); const gainNode = audioCtx.createGain();
                osc.type = 'triangle'; osc.frequency.setValueAtTime(1200, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1800, audioCtx.currentTime + 0.1);
                gainNode.gain.setValueAtTime(0, audioCtx.currentTime);
                gainNode.gain.linearRampToValueAtTime(0.08, audioCtx.currentTime + 0.05);
                gainNode.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.3);
                osc.connect(gainNode); gainNode.connect(audioCtx.destination);
                osc.start(); osc.stop(audioCtx.currentTime + 0.3);
            } catch(e){}
        }

        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {});
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
            }
        }

        const canvas = document.getElementById('matrix-canvas'); const ctx = canvas.getContext('2d');
        function resizeCanvas() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        resizeCanvas(); window.addEventListener('resize', resizeCanvas);
        const alphabet = "ｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓ1234567890".split("");
        const fontSize = 14; let columns = canvas.width / fontSize; let rainDrops = Array(Math.floor(columns)).fill(1);
        function drawMatrix() {
            ctx.fillStyle = 'rgba(0, 0, 0, 0.07)'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.font = fontSize + 'px monospace';
            for (let i = 0; i < rainDrops.length; i++) {
                const text = alphabet[Math.floor(Math.random() * alphabet.length)];
                ctx.fillStyle = Math.random() > 0.98 ? '#fff' : '#00ff41';
                ctx.fillText(text, i * fontSize, rainDrops[i] * fontSize);
                if (rainDrops[i] * fontSize > canvas.height && Math.random() > 0.975) rainDrops[i] = 0;
                rainDrops[i]++;
            }
        }
        setInterval(drawMatrix, 35);

        function toggleAuth() {
            const formType = document.getElementById('form_type'); const submitBtn = document.getElementById('submit-btn');
            const toggleLink = document.getElementById('toggle-auth'); const colorSelector = document.getElementById('color-selector');
            playTerminalBeep(800, 0.05);
            if (formType.value === 'login') {
                formType.value = 'register'; submitBtn.innerText = 'Zapisz profil'; colorSelector.style.display = 'block'; toggleLink.innerText = '[ Logowanie ]';
            } else {
                formType.value = 'login'; submitBtn.innerText = 'Uruchom Łącze'; colorSelector.style.display = 'none'; toggleLink.innerText = '[ Utwórz profil ]';
            }
        }

        function printTerminalSystemAlert(alertContent, targetChannel = null) {
            const destWindow = chatWindow; if (!destWindow) return;
            if(targetChannel && currentChannel !== targetChannel) return;
            playTerminalAlertSound();
            const div = document.createElement('div'); div.className = 'msg-line terminal-inline-alert';
            const now = new Date().toLocaleTimeString();
            div.innerHTML = `<span class='time-tag' style='color:#ff3333;'>[${now}]</span> [ALARM SYSTEMOWY]: ${alertContent}`;
            destWindow.appendChild(div); destWindow.scrollTop = destWindow.scrollHeight;
        }

        function handleTypingEffect(input) {
            document.getElementById('char-counter').innerText = input.value.length + " / 500 p.";
            document.getElementById('matrix-typing-status').style.display = input.value.length > 0 ? 'block' : 'none';
        }

        let animatedLines = new Set();
        let initialLoadDone = false;
        const myNickname = "<?php echo $_SESSION['matrix_user'] ?? ''; ?>";

        function renderMessageNode(m) {
            if (animatedLines.has(m.id)) return null;
            animatedLines.add(m.id);

            const div = document.createElement('div'); div.className = 'msg-line';
            const privTag = m.is_priv ? "<span style='color:#ff3333; font-weight:bold;'>[PRIV] </span>" : "";
            div.innerHTML = `<span class='time-tag'>[${m.time}]</span> ${privTag}<span class='user-tag' style='color:${m.color}; text-shadow:0 0 3px ${m.color};'>${m.sender}</span>: <span class='msg-text'></span>`;
            const textNode = div.querySelector('.msg-text');

            if (m.msg.startsWith('::FILE_TAG::')) {
                const parts = m.msg.split('::'); const webPath = parts[2]; const fileName = parts[3]; const fileExt = parts[4].toLowerCase();
                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt)) {
                    textNode.innerHTML = `<br><img src="${webPath}" class="matrix-uploaded-img" alt="Zrzut" onclick="window.open(this.src)">`;
                } else {
                    textNode.innerHTML = `<br><a href="${webPath}" target="_blank" download="${fileName}" class="matrix-file-link" style="color:#3399ff; text-decoration:underline; font-weight:bold; display:inline-block; margin-top:5px;">[POBIERZ PLIK: ${fileName}]</a>`;
                }
            } else {
                let iterations = 0;
                const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
                const isAutoScroll = (chatWindow.scrollTop + chatWindow.clientHeight >= chatWindow.scrollHeight - 50);
                
                const interval = setInterval(() => {
                    textNode.innerText = m.msg.split("").map((letter, index) => {
                        if(index < iterations) return letter;
                        if(letter === ' ') return ' ';
                        return letters[Math.floor(Math.random() * letters.length)];
                    }).join("") + "█";
                    
                    if(isAutoScroll) chatWindow.scrollTop = chatWindow.scrollHeight;

                    if(iterations >= m.msg.length) {
                        clearInterval(interval);
                        textNode.innerHTML = m.msg; 
                        if(isAutoScroll) chatWindow.scrollTop = chatWindow.scrollHeight;
                    }
                    iterations += Math.max(1, m.msg.length / 15);
                }, 30);
            }
            return div;
        }

        <?php if (isset($_SESSION['matrix_user'])): ?>
        const chatWindow = document.getElementById('chat-window');
        const onlineList = document.getElementById('online-list');
        const tabsContainer = document.getElementById('chat-tabs-container');
        const alertBanner = document.getElementById('system-alert-banner');
        const alertText = document.getElementById('system-alert-text');
        const uploadStatusText = document.getElementById('upload-status-text');
        const mainTerminalFrame = document.getElementById('matrix-main-terminal-frame');
        const latencyDisplay = document.getElementById('matrix-latency-display');
        
        let currentChannel = 'global'; let openPrivs = []; let activeInviteSender = null; let declinedTabsNotified = new Set(); 

        function syncTerminalStream() {
            const startTime = performance.now();
            fetch(`index.php?action=get_messages&target=${encodeURIComponent(currentChannel)}`)
                .then(res => res.json()).then(data => {
                    const duration = Math.round(performance.now() - startTime);
                    latencyDisplay.innerText = `PING: ${duration}ms | STATUS: STABLE`;

                    const shouldScroll = chatWindow.scrollTop + chatWindow.clientHeight >= chatWindow.scrollHeight - 100;
                    let hasNewIncomingMessage = false;

                    if (data.messages.length === 0 && chatWindow.children.length === 0) {
                        chatWindow.innerHTML = `<div class='system-msg'>[SYSTEM]: Kanał [${currentChannel.toUpperCase()}] jest pusty. Szyfrowanie Aktywne.</div>`;
                    } else if (data.messages.length > 0) {
                        if(chatWindow.querySelector('.system-msg')) chatWindow.innerHTML = '';
                        data.messages.forEach(m => {
                            if (!animatedLines.has(m.id) && m.sender !== myNickname) {
                                hasNewIncomingMessage = true;
                            }
                            const node = renderMessageNode(m); if (node) chatWindow.appendChild(node);
                        });
                    }

                    if (hasNewIncomingMessage && initialLoadDone) { playMessageReceiveSound(); }
                    initialLoadDone = true;

                    if (shouldScroll && data.messages.length > 0 && !hasNewIncomingMessage) chatWindow.scrollTop = chatWindow.scrollHeight;
                    handleSystemInvitesLogic(data.invites);
                }).catch(() => {});

            fetch('index.php?action=get_online').then(res => res.json()).then(data => {
                onlineList.innerHTML = '';
                data.forEach(u => {
                    const div = document.createElement('div'); div.className = 'online-user';
                    if (u.name === myNickname) {
                        div.innerHTML = `<span class='online-dot' style='background-color:#fff; box-shadow:0 0 4px #fff;'></span><span style='color:${u.color}; font-weight:bold;'>${u.name} (TY)</span>`;
                    } else {
                        div.style.cursor = 'pointer'; div.onclick = () => requestPriv(u.name);
                        div.innerHTML = `<span class='online-dot'></span><span style='color:${u.color};'>${u.name}</span>`;
                    }
                    onlineList.appendChild(div);
                });
            }).catch(() => {});
        }

        function sendMessage(e) {
            e.preventDefault(); const input = document.getElementById('message-input'); const message = input.value.trim();
            if (!message) return;
            document.getElementById('matrix-typing-status').style.display = 'none';
            if (message.startsWith('/')) { handleMatrixCommands(message); input.value = ''; return; }
            playTerminalBeep(900, 0.03);

            const p = new URLSearchParams();
            p.append('message', message); p.append('target', currentChannel); p.append('csrf_token', CSRF_TOKEN);

            fetch('index.php?action=send_message', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: p
            }).then(res => res.json()).then(data => { if(data.status === 'success') { input.value = ''; syncTerminalStream(); } });
        }

        function uploadMatrixFile(inputElement) {
            if (inputElement.files.length === 0) return;
            const formData = new FormData();
            formData.append('matrix_file', inputElement.files[0]); formData.append('target', currentChannel); formData.append('csrf_token', CSRF_TOKEN);

            uploadStatusText.style.display = 'block';
            fetch('index.php?action=upload_file', { method: 'POST', body: formData })
            .then(res => res.json()).then(data => {
                uploadStatusText.style.display = 'none'; inputElement.value = '';
                if(data.status === 'success') { syncTerminalStream(); }
                else if(data.status === 'error_type') printTerminalSystemAlert('Odmowa. Niedozwolony typ pliku.');
                else printTerminalSystemAlert('Błąd transferu.');
            });
        }

        function handleSystemInvitesLogic(invites) {
            let anyPendingInvite = false;
            for (const sender in invites) {
                const status = invites[sender];
                if (status === 'declined' || status === 'accepted') {
                    if (activeInviteSender === sender) activeInviteSender = null; continue;
                }
                if (status === 'pending') {
                    anyPendingInvite = true;
                    if (activeInviteSender !== sender) {
                        activeInviteSender = sender;
                        alertText.innerText = `[PRIV] ${sender} żąda połączenia.`;
                        alertBanner.style.display = 'block';
                        
                        document.getElementById('btn-accept-invite').onclick = function() {
                            sendSystemSignal(sender, '__SYS_ACCEPT__'); alertBanner.style.display = 'none';
                            activeInviteSender = null; openPrivDirectly(sender);
                        };
                        document.getElementById('btn-decline-invite').onclick = function() {
                            sendSystemSignal(sender, '__SYS_DECLINE__'); alertBanner.style.display = 'none';
                            activeInviteSender = null;
                        };
                    }
                }
            }
            openPrivs.forEach(sender => {
                if (invites[sender] === 'declined' && !declinedTabsNotified.has(sender)) {
                    declinedTabsNotified.add(sender); printTerminalSystemAlert(`Połączenie odrzucone przez: ${sender}.`, sender);
                }
            });
            if (!anyPendingInvite) { alertBanner.style.display = 'none'; activeInviteSender = null; }
        }

        function sendSystemSignal(user, signalType) {
            const p = new URLSearchParams(); p.append('message', user); p.append('target', signalType); p.append('csrf_token', CSRF_TOKEN);
            fetch('index.php?action=send_message', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: p });
        }
        function requestPriv(username) {
            if (username === myNickname) return;
            if (declinedTabsNotified.has(username)) declinedTabsNotified.delete(username);
            openPrivDirectly(username); printTerminalSystemAlert(`Inicjalizacja PRIV z ${username}...`, username);
            sendSystemSignal(username, '__SYS_INVITE__');
        }
        function openPrivDirectly(username) {
            if (!openPrivs.includes(username)) { openPrivs.push(username); renderTabs(); }
            switchChannel(username);
        }
        function closePriv(username, event) {
            event.stopPropagation(); openPrivs = openPrivs.filter(item => item !== username);
            if (declinedTabsNotified.has(username)) declinedTabsNotified.delete(username);
            renderTabs(); if (currentChannel === username) switchChannel('global');
        }
        function switchChannel(channel) {
            currentChannel = channel; renderTabs();
            mainTerminalFrame.className = channel !== 'global' ? 'terminal-box priv-mode-active' : 'terminal-box';
            chatWindow.innerHTML = ''; animatedLines.clear(); syncTerminalStream();
        }

        function renderTabs() {
            let html = `<div class="tab ${currentChannel === 'global' ? 'active' : ''}" id="tab-global" onclick="switchChannel('global')">[GLOBAL]</div>`;
            openPrivs.forEach(user => {
                const isCurrent = (currentChannel === user);
                html += `<div class="tab ${isCurrent ? 'active tab-priv-style' : ''}" id="tab-priv-${user}" onclick="switchChannel('${user}')">[PRIV: ${user}]<span class="close-tab" onclick="closePriv('${user}', event)">×</span></div>`;
            });
            tabsContainer.innerHTML = html;
        }

        function changeLiveColor(val) {
            if (!val) return;
            const p = new URLSearchParams(); p.append('action', 'change_color_live'); p.append('color', val); p.append('csrf_token', CSRF_TOKEN);
            fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: p }).then(() => location.reload());
        }

        function handleMatrixCommands(cmd) {
            const primary = cmd.split(' ')[0].toLowerCase();
            if (primary === '/help') {
                printTerminalSystemAlert("POWŁOKA:\n/help - Komendy\n/ping - Ping\n/clear - Czyszczenie ekranu\n/whoami - Profil");
            } else if (primary === '/ping') {
                printTerminalSystemAlert("PONG: Połączenie stabilne.");
            } else if (primary === '/clear') {
                chatWindow.innerHTML = ''; animatedLines.clear(); printTerminalSystemAlert("Ekran wyczyszczony.");
            } else if (primary === '/whoami') {
                printTerminalSystemAlert(`ID: ${myNickname} | NODE: ${currentChannel}`);
            } else { printTerminalSystemAlert("Nieznana komenda."); }
        }

        syncTerminalStream(); setInterval(syncTerminalStream, 1500);
        <?php endif; ?>
    </script>
</body>
</html>
