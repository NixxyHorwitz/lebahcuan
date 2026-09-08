<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user($pdo);
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesi berakhir, silakan login kembali.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF check for mutating actions
if (in_array($action, ['harvest_hive', 'sell_honey', 'buy_hive', 'buy_bee', 'buy_stall'], true)) {
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'msg' => 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.']);
        exit;
    }
}

switch ($action) {
    case 'get_hive_detail':
        $hive_id = (int)($_GET['hive_id'] ?? $_POST['hive_id'] ?? 0);
        $detail = BeeFarm::getHiveDetails($pdo, $hive_id, (int)$user['id']);
        if (!$detail) {
            echo json_encode(['ok' => false, 'msg' => 'Kandang tidak ditemukan.']);
            exit;
        }
        echo json_encode(['ok' => true, 'data' => $detail]);
        exit;

    case 'harvest_hive':
        $hive_id = (int)($_POST['hive_id'] ?? 0);
        $res = BeeFarm::harvestHive($pdo, (int)$user['id'], $hive_id);
        echo json_encode($res);
        exit;

    case 'sell_honey':
        $amount_ml = (float)($_POST['amount_ml'] ?? 0);
        $res = BeeFarm::sellHoney($pdo, (int)$user['id'], $amount_ml);
        echo json_encode($res);
        exit;

    case 'buy_hive':
        $hive_master_id = (int)($_POST['hive_master_id'] ?? 0);
        $res = BeeFarm::buyHive($pdo, (int)$user['id'], $hive_master_id);
        echo json_encode($res);
        exit;

    case 'buy_bee':
        $bee_type_id = (int)($_POST['bee_type_id'] ?? 0);
        $target_hive_id = (int)($_POST['target_hive_id'] ?? 0);
        $res = BeeFarm::buyBee($pdo, (int)$user['id'], $bee_type_id, $target_hive_id);
        echo json_encode($res);
        exit;

    case 'buy_stall':
        $stall_master_id = (int)($_POST['stall_master_id'] ?? 0);
        $res = BeeFarm::buyStall($pdo, (int)$user['id'], $stall_master_id);
        echo json_encode($res);
        exit;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
        exit;
}
