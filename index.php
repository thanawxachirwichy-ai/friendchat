<?php
session_start();
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASSWORD') ?: '';
$db_name = getenv('DB_NAME') ?: 'friendchat';

$conn = new mysqli($db_host, $db_user, $db_pass);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->query("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($db_name);

$conn->query("CREATE TABLE IF NOT EXISTS `users` (
 `id` INT AUTO_INCREMENT PRIMARY KEY,
 `username` VARCHAR(255) NOT NULL UNIQUE,
 `display_name` VARCHAR(255) NOT NULL,
 `password` VARCHAR(255) NOT NULL,
 `avatar` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS `friends` (
 `id` INT AUTO_INCREMENT PRIMARY KEY,
 `user_id` INT NOT NULL,
 `friend_id` INT NOT NULL,
 `status` ENUM('pending', 'accepted') DEFAULT 'pending',
 UNIQUE KEY `friendship` (`user_id`, `friend_id`),
 FOREIGN KEY(`user_id`) REFERENCES users(`id`) ON DELETE CASCADE,
 FOREIGN KEY(`friend_id`) REFERENCES users(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS `messages` (
 `id` INT AUTO_INCREMENT PRIMARY KEY,
 `sender_id` INT NOT NULL,
 `receiver_id` INT NULL, 
 `message` TEXT NULL,
 `file_path` VARCHAR(255) NULL,
 `file_type` VARCHAR(50) NULL,
 `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(`sender_id`) REFERENCES users(`id`) ON DELETE CASCADE,
 FOREIGN KEY(`receiver_id`) REFERENCES users(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$chat_upload_dir = 'uploads/';
$profile_upload_dir = 'profile/';
if (!file_exists($chat_upload_dir)) { mkdir($chat_upload_dir, 0777, true); }
if (!file_exists($profile_upload_dir)) { mkdir($profile_upload_dir, 0777, true); }

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
function csrf_check() {
 if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) { die("CSRF TOKEN ERROR"); }
}

$action = $_GET['action'] ?? '';
$my_id = $_SESSION['user_id'] ?? 0;
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

if (isset($_GET['fetch_messages']) && $my_id) {
 $chat_with = intval($_GET['chat_with'] ?? 0);
 $filtered_messages = [];
 if ($chat_with > 0) {
 $stmt = $conn->prepare("SELECT m.*, u.display_name, u.username, u.avatar FROM messages m JOIN users u ON m.sender_id = u.id WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?) ORDER BY m.created_at ASC");
 $stmt->bind_param("iiii", $my_id, $chat_with, $chat_with, $my_id);
 $stmt->execute();
 $res = $stmt->get_result();
 while($row = $res->fetch_assoc()) {
 $row['is_me'] = ($row['sender_id'] == $my_id);
 $row['avatar'] = $row['avatar'] ?: 'https://via.placeholder.com/150';
 $row['time'] = date('H:i', strtotime($row['created_at']));
 $filtered_messages[] = $row;
 }
 $stmt->close();
 }
 header('Content-Type: application/json');
 echo json_encode($filtered_messages);
 exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 if ($action === 'register') {
 csrf_check();
 $username = trim($_POST['username']);
 $display_name = trim($_POST['display_name']) ?: $username;
 $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
 $stmt = $conn->prepare("INSERT INTO users (username, display_name, password) VALUES (?, ?, ?)");
 $stmt->bind_param("sss", $username, $display_name, $password);
 if ($stmt->execute()) {
 header("Location: ?msg=" . urlencode("สมัครสมาชิกสำเร็จ! เข้าสู่ระบบได้เลย"));
 } else { 
 header("Location: ?error=" . urlencode("ชื่อไอดีผู้ใช้นี้ถูกใช้ไปแล้ว")); 
 }
 $stmt->close(); exit;
 }
 
 if ($action === 'login') {
 csrf_check();
 $username = trim($_POST['username']);
 $password = $_POST['password'];
 $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
 $stmt->bind_param("s", $username);
 $stmt->execute();
 $res = $stmt->get_result();
 if ($row = $res->fetch_assoc()) {
 if (password_verify($password, $row['password'])) {
 $_SESSION['user_id'] = $row['id'];
 $_SESSION['username'] = $row['username'];
 header("Location: index.php"); exit;
 }
 }
 header("Location: ?error=" . urlencode("ไอดีหรือรหัสผ่านไม่ถูกต้อง")); 
 $stmt->close(); exit;
 }
 
 if ($action === 'update_profile' && $my_id) {
 csrf_check();
 if (isset($_POST['change_name_check'])) {
 $new_name = trim($_POST['new_display_name']);
 if (!empty($new_name)) {
 $stmt = $conn->prepare("UPDATE users SET display_name = ? WHERE id = ?");
 $stmt->bind_param("si", $new_name, $my_id);
 $stmt->execute(); $stmt->close();
 }
 }
 if (isset($_POST['change_avatar_check'])) {
 $base64_data = $_POST['avatar_base64'] ?? '';
 if (!empty($base64_data)) {
 $data_pieces = explode(',', $base64_data);
 $image_bytes = base64_decode($data_pieces[1]);
 $filename = 'profile_' . $my_id . '_' . time() . '.jpg';
 $full_path = $profile_upload_dir . $filename;
 if (file_put_contents($full_path, $image_bytes)) {
 $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
 $stmt->bind_param("si", $full_path, $my_id);
 $stmt->execute(); $stmt->close();
 }
 }
 }
 header("Location: index.php"); exit;
 }
 
 if ($action === 'send_message' && $my_id) {
 $receiver_id = intval($_POST['receiver_id'] ?? 0);
 $message = trim($_POST['chat_msg'] ?? '');
 $file_path = null; $file_type = null;
 if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK) {
 $ext = strtolower(pathinfo($_FILES['chat_file']['name'], PATHINFO_EXTENSION));
 $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
 $target = $chat_upload_dir . $filename;
 if (move_uploaded_file($_FILES['chat_file']['tmp_name'], $target)) {
 $file_path = $target;
 $file_type = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 'image' : 'file';
 }
 }
 if (($message !== '' || $file_path !== null) && $receiver_id > 0) {
 $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, file_path, file_type) VALUES (?, ?, ?, ?, ?)");
 $stmt->bind_param("iisss", $my_id, $receiver_id, $message, $file_path, $file_type);
 $stmt->execute(); $stmt->close();
 }
 header('Content-Type: application/json');
 echo json_encode(['status' => 'success']); exit;
 }
 
 if ($action === 'friend_manage' && $my_id) {
 csrf_check();
 $f_action = $_POST['friend_action'] ?? '';
 $target_id = intval($_POST['target_id'] ?? 0);
 if ($f_action === 'add') {
 $stmt = $conn->prepare("INSERT IGNORE INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')");
 $stmt->bind_param("ii", $my_id, $target_id);
 $stmt->execute(); $stmt->close();
 } elseif ($f_action === 'accept') {
 $stmt = $conn->prepare("UPDATE friends SET status='accepted' WHERE user_id=? AND friend_id=?");
 $stmt->bind_param("ii", $target_id, $my_id);
 $stmt->execute(); $stmt->close();
 } elseif ($f_action === 'reject' || $f_action === 'delete') {
 $stmt = $conn->prepare("DELETE FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)");
 $stmt->bind_param("iiii", $target_id, $my_id, $my_id, $target_id);
 $stmt->execute(); $stmt->close();
 }
 header("Location: index.php"); exit;
 }
 
 if ($action === 'delete_message' && $my_id) {
 csrf_check();
 $msg_id = intval($_POST['message_id'] ?? 0);
 $stmt = $conn->prepare("SELECT file_path FROM messages WHERE id=? AND sender_id=?");
 $stmt->bind_param("ii", $msg_id, $my_id);
 $stmt->execute();
 $res = $stmt->get_result();
 if($row = $res->fetch_assoc()) {
 if ($row['file_path'] && file_exists($row['file_path'])) { unlink($row['file_path']); }
 $del_stmt = $conn->prepare("DELETE FROM messages WHERE id=?");
 $del_stmt->bind_param("i", $msg_id);
 $del_stmt->execute(); $del_stmt->close();
 }
 $stmt->close();
 header('Content-Type: application/json');
 echo json_encode(['status' => 'deleted']); exit;
 }
}

if ($action === 'logout') { session_destroy(); header("Location: index.php"); exit; }

$my_info = $my_id ? $conn->query("SELECT * FROM users WHERE id=$my_id")->fetch_assoc() : [];
$my_avatar = (!empty($my_info['avatar'])) ? $my_info['avatar'] : 'https://via.placeholder.com/150';
?>
<!DOCTYPE html>
<html lang="th">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
 <title>💬 FriendChat</title>
 <script src="https://cdn.tailwindcss.com"></script>
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css">
 <script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>
 <style>
 body { overflow-x: hidden; }
 @media (max-width: 768px) {
 .sidebar-mobile { display: none; }
 .sidebar-mobile.show { display: flex; }
 }
 </style>
</head>
<body class="bg-gray-100 h-screen flex justify-center items-center m-0 font-sans overflow-hidden">
<div class="w-full h-full md:h-[92vh] md:max-w-6xl bg-white md:shadow-2xl md:rounded-2xl overflow-hidden flex flex-col md:flex-row">
 
 <?php if (!$my_id): ?>
 <div class="m-auto w-full max-w-md p-6 md:p-8 bg-white border border-gray-100 rounded-2xl text-center">
 <h2 class="text-2xl md:text-3xl font-bold mb-2 text-blue-600">💬 FriendChat</h2>
 <p class="text-xs md:text-sm text-gray-500 mb-6">ค้นหาชื่อผู้ใช้ เป็นเพื่อนกันก่อนแชท</p>
 
 <?php if (!empty($error)): ?><div class="p-3 mb-4 text-xs md:text-sm rounded-lg bg-red-50 text-red-700"><?=htmlspecialchars($error)?></div><?php endif; ?>
 <?php if (!empty($message)): ?><div class="p-3 mb-4 text-xs md:text-sm rounded-lg bg-green-50 text-green-700"><?=htmlspecialchars($message)?></div><?php endif; ?>
 
 <div class="mb-6 flex justify-around border-b pb-2">
 <button onclick="toggleAuth('login')" id="tab-login-btn" class="font-bold text-blue-600 border-b-2 border-blue-600 pb-2 w-1/2 text-center cursor-pointer text-xs md:text-sm">เข้าสู่ระบบ</button>
 <button onclick="toggleAuth('reg')" id="tab-reg-btn" class="font-bold text-gray-400 w-1/2 text-center cursor-pointer text-xs md:text-sm">สมัครสมาชิก</button>
 </div>
 
 <form id="form-login" action="?action=login" method="POST" class="space-y-3 md:space-y-4">
 <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
 <input type="text" name="username" placeholder="ไอดีผู้ใช้" required class="w-full px-3 md:px-4 py-2.5 md:py-3 border border-gray-200 rounded-xl focus:outline-none text-xs md:text-sm">
 <input type="password" name="password" placeholder="รหัสผ่าน" required class="w-full px-3 md:px-4 py-2.5 md:py-3 border border-gray-200 rounded-xl focus:outline-none text-xs md:text-sm">
 <button type="submit" class="w-full py-2.5 md:py-3 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-700 transition text-xs md:text-sm">เข้าสู่ระบบ</button>
 </form>
 
 <form id="form-register" action="?action=register" method="POST" class="space-y-3 md:space-y-4 hidden">
 <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
 <input type="text" name="username" placeholder="ไอดีผู้ใช้" required class="w-full px-3 md:px-4 py-2.5 md:py-3 border border-gray-200 rounded-xl focus:outline-none text-xs md:text-sm">
 <input type="text" name="display_name" placeholder="ชื่อเล่น" class="w-full px-3 md:px-4 py-2.5 md:py-3 border border-gray-200 rounded-xl focus:outline-none text-xs md:text-sm">
 <input type="password" name="password" placeholder="รหัสผ่าน" required class="w-full px-3 md:px-4 py-2.5 md:py-3 border border-gray-200 rounded-xl focus:outline-none text-xs md:text-sm">
 <button type="submit" class="w-full py-2.5 md:py-3 bg-emerald-600 text-white rounded-xl font-bold hover:bg-emerald-700 transition text-xs md:text-sm">สร้างบัญชี</button>
 </form>
 </div>
 
 <script>
 function toggleAuth(mode) {
 if (mode === 'login') {
 document.getElementById('form-login').classList.remove('hidden');
 document.getElementById('form-register').classList.add('hidden');
 document.getElementById('tab-login-btn').className = "font-bold text-blue-600 border-b-2 border-blue-600 pb-2 w-1/2 text-center cursor-pointer text-xs md:text-sm";
 document.getElementById('tab-reg-btn').className = "font-bold text-gray-400 w-1/2 text-center cursor-pointer text-xs md:text-sm";
 } else {
 document.getElementById('form-login').classList.add('hidden');
 document.getElementById('form-register').classList.remove('hidden');
 document.getElementById('tab-reg-btn').className = "font-bold text-emerald-600 border-b-2 border-emerald-600 pb-2 w-1/2 text-center cursor-pointer text-xs md:text-sm";
 document.getElementById('tab-login-btn').className = "font-bold text-gray-400 w-1/2 text-center cursor-pointer text-xs md:text-sm";
 }
 }
 </script>
 
 <?php else: ?>
 <header class="bg-blue-600 px-3 md:px-6 py-2.5 md:py-3.5 flex justify-between items-center text-white shrink-0 shadow-md">
 <button id="mobile-menu-btn" class="md:hidden text-xl cursor-pointer" onclick="toggleMobileMenu()">☰</button>
 <div class="flex items-center space-x-2 md:space-x-3 cursor-pointer group flex-1 md:flex-none" onclick="openProfileModal()">
 <div class="relative">
 <img src="<?=$my_avatar?>" class="w-8 h-8 md:w-10 md:h-10 rounded-full object-cover border-2 border-white/80 group-hover:opacity-85 transition">
 <span class="absolute bottom-0 right-0 bg-green-400 w-2 h-2 md:w-3 md:h-3 rounded-full border-2 border-blue-600"></span>
 </div>
 <div class="hidden md:block">
 <span class="font-bold text-sm md:text-base block leading-tight"><?=htmlspecialchars($my_info['display_name'])?></span>
 <span class="text-[10px] md:text-[11px] text-blue-100 block">@<?=htmlspecialchars($my_info['username'])?></span>
 </div>
 </div>
 <a href="?action=logout" class="bg-red-500 hover:bg-red-600 px-3 md:px-4 py-1 md:py-1.5 rounded-xl text-[10px] md:text-xs font-bold shadow transition text-white">ออก</a>
 </header>
 
 <aside id="sidebar" class="sidebar-mobile w-full md:w-80 bg-gray-50 border-r border-gray-200 flex flex-col shrink-0">
 <div class="p-2 bg-white border-b flex justify-around text-[10px] md:text-xs font-bold shrink-0">
 <button onclick="switchTab('tab-friends')" id="btn-tab-friends" class="text-blue-600 border-b-2 border-blue-600 pb-2 flex-1 text-center cursor-pointer">💬 เพื่อน</button>
 <button onclick="switchTab('tab-search')" id="btn-tab-search" class="text-gray-400 pb-2 flex-1 text-center cursor-pointer">🔍 หา</button>
 <button onclick="switchTab('tab-requests')" id="btn-tab-requests" class="text-gray-400 pb-2 flex-1 text-center cursor-pointer relative">📨 คำขอ 
 <?php 
 $count_req = $conn->query("SELECT COUNT(*) as total FROM friends WHERE friend_id = $my_id AND status = 'pending'")->fetch_assoc();
 if($count_req['total'] > 0): echo "<span class='absolute top-0 right-2 bg-red-500 text-white text-[8px] md:text-[9px] w-4 h-4 rounded-full flex items-center justify-center'>".$count_req['total']."</span>"; endif;
 ?>
 </button>
 </div>
 
 <div class="p-2 md:p-3 bg-white border-b shrink-0">
 <div class="relative">
 <span class="absolute inset-y-0 left-0 flex items-center pl-2 md:pl-3 text-gray-400 text-xs">🔍</span>
 <input type="text" id="side-search-input" onkeyup="filterSidebarList()" placeholder="ค้นชื่อ..." class="w-full pl-7 md:pl-8 pr-3 md:pr-4 py-1.5 border border-gray-200 rounded-xl text-[11px] md:text-xs outline-none bg-gray-50 focus:bg-white transition focus:border-blue-500">
 </div>
 </div>
 
 <div class="flex-1 overflow-y-auto p-2" id="sidebar-container">
 <div id="tab-friends" class="sidebar-tab space-y-1">
 <?php
 $friends = $conn->query("SELECT u.* FROM friends f JOIN users u ON (f.friend_id = u.id AND f.user_id = $my_id) OR (f.user_id = u.id AND f.friend_id = $my_id) WHERE f.status='accepted' AND u.id != $my_id");
 if($friends->num_rows == 0) echo "<p class='text-[10px] md:text-xs text-gray-400 text-center py-8 px-4'>ยังไม่มีเพื่อน</p>";
 while($f = $friends->fetch_assoc()):
 $f_av = $f['avatar'] ?: 'https://via.placeholder.com/150';
 ?>
 <div onclick="selectChat(<?=$f['id']?>, '<?=htmlspecialchars($f['display_name'])?>')" id="user-card-<?=$f['id']?>" class="item-card flex items-center p-2 md:p-2.5 rounded-xl cursor-pointer transition hover:bg-gray-200/60 border border-transparent">
 <img src="<?=$f_av?>" class="w-8 h-8 md:w-10 md:h-10 rounded-full object-cover shrink-0 mr-2 md:mr-3 border border-gray-200">
 <div class="flex-1 min-w-0">
 <p class="font-bold text-xs md:text-sm text-gray-800 truncate target-name"><?=htmlspecialchars($f['display_name'])?></p>
 <p class="text-[9px] md:text-[10px] text-gray-400 truncate">@<?=htmlspecialchars($f['username'])?></p>
 </div>
 <span class="text-[8px] md:text-[9px] bg-blue-100 text-blue-700 font-bold px-1.5 md:px-2 py-0.5 rounded-full shrink-0">แชท</span>
 </div>
 <?php endwhile; ?>
 </div>
 
 <div id="tab-search" class="sidebar-tab space-y-1 hidden">
 <?php
 $all_users = $conn->query("SELECT * FROM users WHERE id != $my_id");
 while($u = $all_users->fetch_assoc()):
 $u_av = $u['avatar'] ?: 'https://via.placeholder.com/150';
 $check = $conn->query("SELECT * FROM friends WHERE (user_id=$my_id AND friend_id={$u['id']}) OR (user_id={$u['id']} AND friend_id=$my_id)")->fetch_assoc();
 ?>
 <div class="item-card flex items-center justify-between p-2 md:p-2.5 bg-white rounded-xl border border-gray-100 shadow-sm">
 <div class="flex items-center min-w-0 mr-2">
 <img src="<?=$u_av?>" class="w-7 h-7 md:w-9 md:h-9 rounded-full object-cover shrink-0 mr-2 border border-gray-100">
 <div class="min-w-0">
 <p class="font-bold text-[10px] md:text-xs text-gray-700 truncate target-name"><?=htmlspecialchars($u['display_name'])?></p>
 <p class="text-[8px] md:text-[9px] text-gray-400 truncate">@<?=htmlspecialchars($u['username'])?></p>
 </div>
 </div>
 <?php if(!$check): ?>
 <form action="?action=friend_manage" method="POST" class="shrink-0">
 <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
 <input type="hidden" name="target_id" value="<?=$u['id']?>">
 <input type="hidden" name="friend_action" value="add">
 <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-[9px] md:text-[10px] px-2 md:px-2.5 py-1 rounded-lg font-bold transition cursor-pointer">+ แอด</button>
 </form>
 <?php else: ?>
 <span class="text-[8px] md:text-[10px] text-gray-400 font-medium shrink-0"><?=($check['status']=='pending'?'⏳ รอ':'✓ เพื่อน')?></span>
 <?php endif; ?>
 </div>
 <?php endwhile; ?>
 </div>
 
 <div id="tab-requests" class="sidebar-tab space-y-1 hidden">
 <?php
 $reqs = $conn->query("SELECT u.* FROM friends f JOIN users u ON f.user_id = u.id WHERE f.friend_id = $my_id AND f.status = 'pending'");
 if($reqs->num_rows == 0) echo "<p class='text-[10px] md:text-xs text-gray-400 text-center py-8'>ไม่มีคำขอ</p>";
 while($req = $reqs->fetch_assoc()):
 $req_av = $req['avatar'] ?: 'https://via.placeholder.com/150';
 ?>
 <div class="item-card bg-white p-2 md:p-2.5 rounded-xl border border-gray-100 shadow-sm flex flex-col space-y-2">
 <div class="flex items-center">
 <img src="<?=$req_av?>" class="w-7 h-7 md:w-8 md:h-8 rounded-full object-cover mr-2">
 <div class="min-w-0">
 <p class="font-bold text-[10px] md:text-xs text-gray-800 truncate target-name"><?=htmlspecialchars($req['display_name'])?></p>
 <p class="text-[8px] md:text-[9px] text-gray-400">@<?=htmlspecialchars($req['username'])?></p>
 </div>
 </div>
 <div class="flex space-x-1 justify-end">
 <form action="?action=friend_manage" method="POST" class="inline">
 <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
 <input type="hidden" name="target_id" value="<?=$req['id']?>">
 <input type="hidden" name="friend_action" value="accept">
 <button type="submit" class="bg-emerald-600 text-white text-[9px] md:text-[10px] px-2 py-0.5 rounded hover:bg-emerald-500 cursor-pointer">รับ</button>
 </form>
 <form action="?action=friend_manage" method="POST" class="inline">
 <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
 <input type="hidden" name="target_id" value="<?=$req['id']?>">
 <input type="hidden" name="friend_action" value="reject">
 <button type="submit" class="bg-rose-600 text-white text-[9px] md:text-[10px] px-2 py-0.5 rounded hover:bg-rose-500 cursor-pointer">ปฏิเสธ</button>
 </form>
 </div>
 </div>
 <?php endwhile; ?>
 </div>
 </div>
 </aside>
 
 <section class="flex-1 bg-slate-50 flex flex-col overflow-hidden">
 <div class="px-3 md:px-6 py-3 md:py-4 bg-white border-b border-gray-200 font-bold text-gray-700 shadow-sm shrink-0">
 <span id="current-chat-title" class="text-xs md:text-base text-gray-800">👋 เลือกเพื่อนเพื่อเริ่มคุย</span>
 </div>
 
 <div class="flex-1 overflow-y-auto p-2 md:p-4 space-y-3 md:space-y-4" id="chat-box">
 <div class="m-auto text-center py-20 text-gray-400 max-w-sm">
 <span class="text-4xl md:text-5xl block mb-2">💬</span>
 <p class="font-bold text-gray-600 text-sm md:text-base">ยินดีต้อนรับ FriendChat</p>
 <p class="text-[10px] md:text-xs text-gray-400 mt-1">แอดเพื่อนแล้วจึงจะคุยได้</p>
 </div>
 </div>
 
 <form class="p-2 md:p-4 bg-white border-t border-gray-200 flex items-center space-x-2 md:space-x-3 shrink-0 hidden" id="chat-form">
 <label class="cursor-pointer p-2 md:p-2.5 bg-gray-100 hover:bg-gray-200 rounded-xl text-lg md:text-xl shrink-0 border border-gray-200" title="แนบไฟล์">
 📁
 <input type="file" id="file-input" class="hidden">
 </label>
 <div class="flex-1 relative flex items-center">
 <input type="text" id="msg-input" placeholder="พิมพ์ข้อความ..." autocomplete="off" class="w-full pl-3 md:pl-4 pr-24 md:pr-32 py-2 md:py-3 border border-gray-200 rounded-xl text-xs md:text-sm outline-none bg-gray-50 focus:bg-white transition focus:border-blue-500">
 <span id="file-indicator" class="absolute right-2 text-[9px] md:text-[10px] bg-amber-100 text-amber-800 px-2 py-1 rounded-md hidden max-w-[100px] truncate"></span>
 </div>
 <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white rounded-xl px-3 md:px-5 py-2 md:py-3 font-bold cursor-pointer shrink-0 text-xs md:text-sm">ส่ง</button>
 </form>
 </section>
 </div>
 
 <div id="profile-modal" class="fixed inset-0 bg-black/60 flex items-center justify-center hidden z-50 p-4">
 <div class="bg-white p-4 md:p-6 rounded-2xl w-full max-w-md shadow-2xl overflow-y-auto max-h-[90vh]">
 <h3 class="text-base md:text-lg font-bold mb-1 text-blue-600">⚙️ แก้ไขข้อมูล</h3>
 <p class="text-[10px] md:text-xs text-gray-400 mb-4">เลือกสิ่งที่ต้องการเปลี่ยน</p>
 
 <form action="?action=update_profile" method="POST" id="form-profile-submit" class="space-y-3 md:space-y-4">
 <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
 <input type="hidden" name="avatar_base64" id="avatar_base64_input">
 
 <div class="border border-gray-100 p-3 rounded-xl bg-gray-50">
 <label class="flex items-center space-x-2 font-bold text-xs md:text-sm text-gray-700 mb-2 cursor-pointer">
 <input type="checkbox" name="change_name_check" id="change_name_check" onchange="document.getElementById('new_display_name').disabled = !this.checked" class="rounded text-blue-600 w-4 h-4">
 <span>เปลี่ยนชื่อเล่น</span>
 </label>
 <input type="text" name="new_display_name" id="new_display_name" value="<?=htmlspecialchars($my_info['display_name'])?>" placeholder="ชื่อใหม่..." class="w-full border p-2 rounded-lg text-xs outline-none bg-white" disabled>
 </div>
 
 <div class="border border-gray-100 p-3 rounded-xl bg-gray-50">
 <label class="flex items-center space-x-2 font-bold text-xs md:text-sm text-gray-700 mb-2 cursor-pointer">
 <input type="checkbox" name="change_avatar_check" id="change_avatar_check" onchange="document.getElementById('input-avatar-file').disabled = !this.checked" class="rounded text-blue-600 w-4 h-4">
 <span>เปลี่ยนรูปภาพ</span>
 </label>
 <input type="file" id="input-avatar-file" accept="image/*" class="w-full text-xs text-gray-500 mb-3" disabled>
 <div id="croppie-wrapper" class="hidden">
 <div id="croppie-container"></div>
 <p class="text-center text-[10px] md:text-[11px] text-gray-400 mt-1">ลากเพื่อตัดรูป</p>
 </div>
 </div>
 
 <div class="flex justify-end space-x-2 pt-2">
 <button type="button" onclick="closeProfileModal()" class="bg-gray-200 text-gray-700 px-3 md:px-4 py-2 rounded-xl text-xs font-semibold cursor-pointer">ยกเลิก</button>
 <button type="button" onclick="processAndSaveProfile()" class="bg-blue-600 text-white px-4 md:px-5 py-2 rounded-xl text-xs font-bold shadow hover:bg-blue-700 cursor-pointer">บันทึก</button>
 </div>
 </form>
 </div>
 </div>
 
 <script>
 let activeChatId = 0;
 let currentInterval = null;
 let croppieInstance = null;
 const csrfToken = "<?=htmlspecialchars($_SESSION['csrf'])?>";
 
 function toggleMobileMenu() {
 const sidebar = document.getElementById('sidebar');
 sidebar.classList.toggle('show');
 }
 
 function filterSidebarList() {
 const input = document.getElementById('side-search-input');
 const filter = input.value.toLowerCase().trim();
 const activeTab = document.querySelector('.sidebar-tab:not(.hidden)');
 if(!activeTab) return;
 const cards = activeTab.getElementsByClassName('item-card');
 for (let i = 0; i < cards.length; i++) {
 const targetSpan = cards[i].querySelector('.target-name');
 if (targetSpan) {
 const text = targetSpan.textContent || targetSpan.innerText;
 cards[i].style.display = text.toLowerCase().indexOf(filter) > -1 ? 'flex' : 'none';
 }
 }
 }
 
 function switchTab(tabId) {
 document.querySelectorAll('.sidebar-tab').forEach(el => el.classList.add('hidden'));
 document.getElementById(tabId).classList.remove('hidden');
 document.getElementById('btn-tab-friends').className = tabId === 'tab-friends' ? 'text-blue-600 border-b-2 border-blue-600 pb-2 flex-1 text-center cursor-pointer' : 'text-gray-400 pb-2 flex-1 text-center cursor-pointer';
 document.getElementById('btn-tab-search').className = tabId === 'tab-search' ? 'text-blue-600 border-b-2 border-blue-600 pb-2 flex-1 text-center cursor-pointer' : 'text-gray-400 pb-2 flex-1 text-center cursor-pointer';
 document.getElementById('btn-tab-requests').className = tabId === 'tab-requests' ? 'text-blue-600 border-b-2 border-blue-600 pb-2 flex-1 text-center cursor-pointer relative' : 'text-gray-400 pb-2 flex-1 text-center cursor-pointer relative';
 filterSidebarList();
 }
 
 function selectChat(friendId, friendName) {
 activeChatId = friendId;
 document.getElementById('current-chat-title').innerText = "💬 " + friendName;
 document.getElementById('chat-form').classList.remove('hidden');
 document.querySelectorAll('#tab-friends .item-card').forEach(el => el.classList.remove('bg-blue-50', 'border-blue-200'));
 const selectedCard = document.getElementById('user-card-' + friendId);
 if(selectedCard) selectedCard.classList.add('bg-blue-50', 'border-blue-200');
 if(window.innerWidth < 768) {
 document.getElementById('sidebar').classList.remove('show');
 }
 fetchMessages();
 if(currentInterval) clearInterval(currentInterval);
 currentInterval = setInterval(fetchMessages, 3000);
 }
 
 function fetchMessages() {
 if (activeChatId === 0) return;
 fetch(`?fetch_messages=1&chat_with=${activeChatId}`)
 .then(res => res.json())
 .then(data => {
 const chatBox = document.getElementById('chat-box');
 let html = '';
 if(data.length === 0) {
 html = `<p class='text-center text-xs text-gray-400 py-10'>เริ่มพิมพ์ข้อความเลย!</p>`;
 } else {
 data.forEach(msg => {
 const isMe = msg.is_me;
 html += `
 <div class="flex items-start ${isMe ? 'flex-row-reverse space-x-reverse' : ''} space-x-2">
 <img src="${msg.avatar}" class="w-6 h-6 md:w-7 md:h-7 rounded-full object-cover mt-0.5 border">
 <div class="max-w-[85%] md:max-w-[70%] group relative">
 <div class="p-2 md:p-3 rounded-2xl text-xs md:text-sm shadow-2xs ${isMe ? 'bg-blue-600 text-white rounded-tr-none' : 'bg-white text-gray-800 rounded-tl-none'}">
 ${msg.message ? `<p class="break-words leading-relaxed">${escapeHtml(msg.message)}</p>` : ''}
 ${msg.file_type === 'image' ? `<img src="${msg.file_path}" class="max-w-xs rounded-lg mt-1 max-h-48 object-contain cursor-pointer border" onclick="window.open('${msg.file_path}')">` : ''}
 ${msg.file_type === 'file' ? `<a href="${msg.file_path}" target="_blank" class="block mt-1 p-2 bg-black/10 rounded font-bold underline truncate">📁 ดาวน์โหลด</a>` : ''}
 </div>
 <div class="flex items-center space-x-1 mt-0.5 px-1 justify-${isMe ? 'end' : 'start'}">
 <span class="text-[8px] md:text-[9px] text-gray-400">${msg.time}</span>
 ${isMe ? `<button onclick="deleteMsg(${msg.id})" class="text-[8px] md:text-[9px] text-red-400 hover:underline cursor-pointer opacity-0 group-hover:opacity-100 transition pl-2">ลบ</button>` : ''}
 </div>
 </div>
 </div>`;
 });
 }
 const isScrolledToBottom = chatBox.scrollHeight - chatBox.clientHeight <= chatBox.scrollTop + 150;
 chatBox.innerHTML = html;
 if(isScrolledToBottom || chatBox.innerHTML.includes('py-10')) {
 chatBox.scrollTop = chatBox.scrollHeight;
 }
 });
 }
 
 document.getElementById('chat-form').addEventListener('submit', function(e) {
 e.preventDefault();
 const msgInput = document.getElementById('msg-input');
 const fileInput = document.getElementById('file-input');
 const msgText = msgInput.value.trim();
 if(msgText === '' && fileInput.files.length === 0) return;
 const formData = new FormData();
 formData.append('receiver_id', activeChatId);
 formData.append('chat_msg', msgText);
 if(fileInput.files.length > 0) {
 formData.append('chat_file', fileInput.files[0]);
 }
 fetch('?action=send_message', {
 method: 'POST',
 body: formData
 })
 .then(res => res.json())
 .then(data => {
 msgInput.value = '';
 fileInput.value = '';
 document.getElementById('file-indicator').classList.add('hidden');
 fetchMessages();
 });
 });
 
 document.getElementById('file-input').addEventListener('change', function() {
 const indicator = document.getElementById('file-indicator');
 if(this.files.length > 0) {
 indicator.innerText = "📎 " + this.files[0].name;
 indicator.classList.remove('hidden');
 } else {
 indicator.classList.add('hidden');
 }
 });
 
 function deleteMsg(msgId) {
 if(!confirm('ลบข้อความนี้?')) return;
 const formData = new FormData();
 formData.append('csrf', csrfToken);
 formData.append('message_id', msgId);
 fetch('?action=delete_message', {
 method: 'POST',
 body: formData
 }).then(() => fetchMessages());
 }
 
 function openProfileModal() {
 document.getElementById('profile-modal').classList.remove('hidden');
 }
 
 function closeProfileModal() {
 document.getElementById('profile-modal').classList.add('hidden');
 if(croppieInstance) { croppieInstance.destroy(); croppieInstance = null; }
 document.getElementById('croppie-wrapper').classList.add('hidden');
 document.getElementById('form-profile-submit').reset();
 }
 
 document.getElementById('input-avatar-file').addEventListener('change', function() {
 if (this.files && this.files[0]) {
 document.getElementById('croppie-wrapper').classList.remove('hidden');
 if(croppieInstance) croppieInstance.destroy();
 croppieInstance = new Croppie(document.getElementById('croppie-container'), {
 viewport: { width: 180, height: 180, type: 'circle' },
 boundary: { width: 260, height: 260 },
 showZoomer: true
 });
 const reader = new FileReader();
 reader.onload = function(e) {
 croppieInstance.bind({ url: e.target.result });
 }
 reader.readAsDataURL(this.files[0]);
 }
 });
 
 function processAndSaveProfile() {
 const changeName = document.getElementById('change_name_check').checked;
 const changeAvatar = document.getElementById('change_avatar_check').checked;
 if(!changeName && !changeAvatar) { alert('เลือกสิ่งที่ต้องการเปลี่ยน'); return; }
 if(changeAvatar && croppieInstance) {
 croppieInstance.result({
 type: 'base64',
 size: { width: 400, height: 400 },
 format: 'jpeg',
 quality: 0.8
 }).then(function(base64) {
 document.getElementById('avatar_base64_input').value = base64;
 document.getElementById('form-profile-submit').submit();
 });
 } else {
 document.getElementById('form-profile-submit').submit();
 }
 }
 
 function escapeHtml(text) {
 return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
 }
 </script>
 <?php endif; ?>
</div>
</body>
</html>
