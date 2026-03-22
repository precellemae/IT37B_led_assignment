<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

require "db.php";

$action = $_GET["action"] ?? "";

// ── Auto-apply schedules ───────────────────────────────
function checkSchedules($conn) {
    $now  = date("H:i");
    $rows = $conn->query("SELECT id, state, schedule_on, schedule_off FROM led_state");
    while ($row = $rows->fetch_assoc()) {
        $newState = null;
        if ($row["schedule_on"]  && substr($row["schedule_on"],  0, 5) === $now && $row["state"] !== "on")  $newState = "on";
        if ($row["schedule_off"] && substr($row["schedule_off"], 0, 5) === $now && $row["state"] !== "off") $newState = "off";
        if ($newState) {
            $id = $row["id"];
            $conn->query("UPDATE led_state SET state='$newState' WHERE id=$id");
            $conn->query("INSERT INTO led_log (led_id, state, source) VALUES ($id, '$newState', 'schedule')");
        }
    }
}

// ── GET all LED states (dashboard polls this) ──────────
if ($action === "get_all") {
    checkSchedules($conn);
    $rows = $conn->query("SELECT id, name, state, brightness, blink, schedule_on, schedule_off FROM led_state ORDER BY id");
    $leds = [];
    while ($row = $rows->fetch_assoc()) $leds[] = $row;
    echo json_encode(["leds" => $leds]);
}

// ── ESP32 polls this — returns simple CSV ──────────────
// Format: "on,255,0|off,200,0|on,128,1"
elseif ($action === "esp32_poll") {
    checkSchedules($conn);
    $rows  = $conn->query("SELECT state, brightness, blink FROM led_state ORDER BY id");
    $parts = [];
    while ($row = $rows->fetch_assoc()) {
        $parts[] = $row["state"] . "," . $row["brightness"] . "," . $row["blink"];
    }
    header("Content-Type: text/plain");
    echo implode("|", $parts);
}

// ── Set state (led_id=0 means ALL) ────────────────────
elseif ($action === "set_state") {
    $body   = json_decode(file_get_contents("php://input"), true);
    $led_id = intval($body["led_id"] ?? 0);
    $state  = $body["state"] ?? "off";

    if (!in_array($state, ["on", "off"])) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid state"]);
        exit;
    }

    if ($led_id === 0) {
        $conn->query("UPDATE led_state SET state='$state'");
        $ids = $conn->query("SELECT id FROM led_state");
        while ($row = $ids->fetch_assoc()) {
            $conn->query("INSERT INTO led_log (led_id, state, source) VALUES ({$row['id']}, '$state', 'dashboard')");
        }
    } else {
        $stmt = $conn->prepare("UPDATE led_state SET state=? WHERE id=?");
        $stmt->bind_param("si", $state, $led_id);
        $stmt->execute();
        $log = $conn->prepare("INSERT INTO led_log (led_id, state, source) VALUES (?, ?, 'dashboard')");
        $log->bind_param("is", $led_id, $state);
        $log->execute();
    }
    echo json_encode(["success" => true]);
}

// ── Set brightness ─────────────────────────────────────
elseif ($action === "set_brightness") {
    $body       = json_decode(file_get_contents("php://input"), true);
    $led_id     = intval($body["led_id"]);
    $brightness = max(0, min(255, intval($body["brightness"] ?? 255)));
    $stmt = $conn->prepare("UPDATE led_state SET brightness=? WHERE id=?");
    $stmt->bind_param("ii", $brightness, $led_id);
    $stmt->execute();
    echo json_encode(["success" => true]);
}

// ── Set blink ──────────────────────────────────────────
elseif ($action === "set_blink") {
    $body   = json_decode(file_get_contents("php://input"), true);
    $led_id = intval($body["led_id"]);
    $blink  = intval($body["blink"] ?? 0);
    $stmt = $conn->prepare("UPDATE led_state SET blink=? WHERE id=?");
    $stmt->bind_param("ii", $blink, $led_id);
    $stmt->execute();
    echo json_encode(["success" => true]);
}

// ── Set schedule ───────────────────────────────────────
elseif ($action === "set_schedule") {
    $body        = json_decode(file_get_contents("php://input"), true);
    $led_id      = intval($body["led_id"]);
    $schedule_on = $body["schedule_on"]  ?: null;
    $schedule_off= $body["schedule_off"] ?: null;
    $stmt = $conn->prepare("UPDATE led_state SET schedule_on=?, schedule_off=? WHERE id=?");
    $stmt->bind_param("ssi", $schedule_on, $schedule_off, $led_id);
    $stmt->execute();
    echo json_encode(["success" => true]);
}

// ── ESP32 heartbeat ────────────────────────────────────
elseif ($action === "heartbeat") {
    $uptime = intval($_GET["uptime"] ?? 0);
    $now    = date("Y-m-d H:i:s");
    $conn->query("INSERT INTO esp32_heartbeat (id, last_seen, uptime) VALUES (1,'$now',$uptime)
                  ON DUPLICATE KEY UPDATE last_seen='$now', uptime=$uptime");
    header("Content-Type: text/plain");
    echo "ok";
}

// ── Get ESP32 status ───────────────────────────────────
elseif ($action === "get_status") {
    $row = $conn->query("SELECT last_seen, uptime FROM esp32_heartbeat WHERE id=1")->fetch_assoc();
    echo json_encode($row ?: ["last_seen" => null, "uptime" => 0]);
}

// ── Get logs ───────────────────────────────────────────
elseif ($action === "get_logs") {
    $rows = $conn->query("
        SELECT l.state, l.source, l.timestamp, s.name, s.id as led_id
        FROM led_log l
        JOIN led_state s ON l.led_id = s.id
        ORDER BY l.id DESC LIMIT 30
    ");
    $logs = [];
    while ($row = $rows->fetch_assoc()) $logs[] = $row;
    echo json_encode($logs);
}

else {
    http_response_code(404);
    echo json_encode(["error" => "Unknown action"]);
}

$conn->close();