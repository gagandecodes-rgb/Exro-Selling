<?php
/**
 * ===============================
 * ✅ SINGLE index.php (Webhook) — ULTRA FAST OPTIMIZED
 * ✅ Selling Bot (COINS + Coupons)
 * ✅ Supabase Postgres via PDO
 * ✅ Deposit: Amazon Gift Card + UPI
 * ✅ Admin Panel + Buy Coupon workflow
 *
 * 🚀 SPEED CHANGES:
 * 1) Instantly replies "OK" to Telegram, then continues processing (FAST WEBHOOK MODE)
 * 2) NO CREATE TABLE IF NOT EXISTS during runtime (run SQL once in Supabase)
 * 3) Prepared-statement caching (static $stmt)
 * 4) Reduced DB calls (combined queries for buy flow)
 * 5) Faster curl timeouts
 * ===============================
 *
 * ✅ RUN THIS ONCE IN SUPABASE (SQL Editor)
 *
 * -- Tables (example minimal; adapt to your schema if already exists)
 * -- bot_settings(skey TEXT PK, svalue TEXT)
 * -- user_states(user_id BIGINT PK, state TEXT, data JSONB, updated_at timestamptz)
 *
 * -- Indexes (IMPORTANT FOR SPEED)
 * CREATE INDEX IF NOT EXISTS idx_users_user_id ON users(user_id);
 * CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders(user_id);
 * CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
 * CREATE INDEX IF NOT EXISTS idx_coupon_codes_ctype_used ON coupon_codes(ctype, is_used);
 * CREATE INDEX IF NOT EXISTS idx_user_states_user_id ON user_states(user_id);
 * CREATE INDEX IF NOT EXISTS idx_coupon_prices_ctype ON coupon_prices(ctype);
 */

// ------------------- CONFIG -------------------
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
$DB_URL    = getenv("DATABASE_URL");

if (!$BOT_TOKEN) { echo "BOT_TOKEN missing"; exit; }
if (!$DB_URL)    { echo "DATABASE_URL missing"; exit; }
if (!$ADMIN_IDS) { echo "ADMIN_IDS missing"; exit; }

date_default_timezone_set("Asia/Kolkata");

// ------------------- DB CONNECT -------------------
function pg_pdo_from_url(string $url): PDO {
    $parts = parse_url($url);
    $user = $parts["user"] ?? "";
    $pass = $parts["pass"] ?? "";
    $host = $parts["host"] ?? "";
    $port = $parts["port"] ?? 5432;
    $db   = ltrim($parts["path"] ?? "", "/");

    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT         => true,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$pdo = pg_pdo_from_url($DB_URL);

// ------------------- FAST WEBHOOK MODE -------------------
// Read update first
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "OK"; exit; }

// Immediately respond to Telegram (prevents webhook timeout / makes UI instant)
ignore_user_abort(true);
ob_start();
echo "OK";
header("Connection: close");
header("Content-Length: " . ob_get_length());
ob_end_flush();
flush();

// ------------------- HELPERS -------------------
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function isAdmin($user_id): bool {
    global $ADMIN_IDS;
    return in_array((string)$user_id, $ADMIN_IDS, true);
}

// ------------------- TELEGRAM (FAST CURL) -------------------
function tg(string $method, array $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_TCP_FASTOPEN   => true,
    ]);

    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, string $text, $reply_markup = null, string $parse_mode = "HTML") {
    $payload = [
        "chat_id"                  => $chat_id,
        "text"                     => $text,
        "parse_mode"               => $parse_mode,
        "disable_web_page_preview" => true
    ];
    if ($reply_markup) $payload["reply_markup"] = $reply_markup;
    return tg("sendMessage", $payload);
}

function sendPhotoMsg($chat_id, string $file_id, string $caption, $reply_markup = null, string $parse_mode = "HTML") {
    $payload = [
        "chat_id"    => $chat_id,
        "photo"      => $file_id,
        "caption"    => $caption,
        "parse_mode" => $parse_mode,
    ];
    if ($reply_markup) $payload["reply_markup"] = $reply_markup;
    return tg("sendPhoto", $payload);
}

function editMessage($chat_id, $message_id, string $text, $reply_markup = null, string $parse_mode="HTML") {
    $payload = [
        "chat_id"                  => $chat_id,
        "message_id"               => $message_id,
        "text"                     => $text,
        "parse_mode"               => $parse_mode,
        "disable_web_page_preview" => true
    ];
    if ($reply_markup) $payload["reply_markup"] = $reply_markup;
    return tg("editMessageText", $payload);
}

function answerCallback($callback_id, string $text = "", bool $showAlert=false) {
    return tg("answerCallbackQuery", [
        "callback_query_id" => $callback_id,
        "text"              => $text,
        "show_alert"        => $showAlert
    ]);
}

// ------------------- UI -------------------
function newBtn($t){ return ["text"=>$t]; }

function main_menu(bool $is_admin=false): array {
    $rows = [
        [newBtn("➕ Add Coins"), newBtn("🛒 Buy Coupon")],
        [newBtn("📦 My Orders"), newBtn("💰 Balance")]
    ];
    if ($is_admin) $rows[] = [newBtn("🛠 Admin Panel")];
    return ["keyboard"=>$rows, "resize_keyboard"=>true];
}

function admin_menu(): array {
    $rows = [
        [newBtn("📦 Stock"), newBtn("💰 Change Prices")],
        [newBtn("📋 Orders List"), newBtn("🧾 Update UPI QR")],
        [newBtn("🎁 Get Free Code"), newBtn("➕ Add Coupon")],
        [newBtn("➖ Remove Coupon")],
        [newBtn("⬅️ Back")]
    ];
    return ["keyboard"=>$rows, "resize_keyboard"=>true];
}

// ------------------- DB: SETTINGS -------------------
function set_setting(string $key, ?string $val): void {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO bot_settings(skey, svalue)
            VALUES(:k,:v)
            ON CONFLICT(skey) DO UPDATE SET svalue=EXCLUDED.svalue
        ");
    }
    $stmt->execute([":k"=>$key, ":v"=>$val]);
}

function get_setting(string $key): ?string {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT svalue FROM bot_settings WHERE skey=:k");
    }
    $stmt->execute([":k"=>$key]);
    $v = $stmt->fetchColumn();
    return ($v !== false) ? (string)$v : null;
}

// ------------------- DB: USER STATE -------------------
function set_state(int $user_id, ?string $state, array $data = []): void {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO user_states(user_id, state, data, updated_at)
            VALUES(:uid, :st, :dt::jsonb, NOW())
            ON CONFLICT (user_id) DO UPDATE
              SET state=:st, data=:dt::jsonb, updated_at=NOW()
        ");
    }
    $stmt->execute([
        ":uid" => $user_id,
        ":st"  => $state,
        ":dt"  => json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);
}

function get_state(int $user_id): array {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT state, data FROM user_states WHERE user_id=:uid");
    }
    $stmt->execute([":uid"=>$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ["state"=>null, "data"=>[]];
    return [
        "state" => $row["state"] ?? null,
        "data"  => !empty($row["data"]) ? json_decode($row["data"], true) : []
    ];
}

function clear_state(int $user_id): void { set_state($user_id, null, []); }

// ------------------- DB: BUSINESS -------------------
function ensure_user(int $user_id, string $username): void {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO users(user_id, username, diamonds)
            VALUES(:uid, :un, 0)
            ON CONFLICT (user_id) DO UPDATE SET username=EXCLUDED.username
        ");
    }
    $stmt->execute([":uid"=>$user_id, ":un"=>$username]);
}

function get_user_coins(int $user_id): int {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT diamonds FROM users WHERE user_id=:uid");
    }
    $stmt->execute([":uid"=>$user_id]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}

function add_user_coins(int $user_id, int $amount): void {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("UPDATE users SET diamonds = diamonds + :a WHERE user_id=:uid");
    }
    $stmt->execute([":a"=>$amount, ":uid"=>$user_id]);
}

function get_price(int $ctype): int {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT price FROM coupon_prices WHERE ctype=:c");
    }
    $stmt->execute([":c"=>$ctype]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}

function set_price(int $ctype, int $price): void {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO coupon_prices(ctype, price)
            VALUES(:c,:p)
            ON CONFLICT(ctype) DO UPDATE SET price=EXCLUDED.price
        ");
    }
    $stmt->execute([":c"=>$ctype, ":p"=>$price]);
}

function stock_count(int $ctype): int {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT COUNT(1) FROM coupon_codes WHERE ctype=:c AND is_used=false");
    }
    $stmt->execute([":c"=>$ctype]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}

function add_coupons(int $ctype, array $codes): int {
    global $pdo;
    static $ins = null;
    if ($ins === null) {
        $ins = $pdo->prepare("
            INSERT INTO coupon_codes(ctype, code, is_used)
            VALUES(:c, :code, false)
            ON CONFLICT (code) DO NOTHING
        ");
    }
    $added = 0;
    foreach ($codes as $code) {
        $code = trim((string)$code);
        if ($code === "") continue;
        $ins->execute([":c"=>$ctype, ":code"=>$code]);
        $added += ($ins->rowCount() ? 1 : 0);
    }
    return $added;
}

function remove_coupons(int $ctype, int $qty): int {
    global $pdo;
    // IMPORTANT: bind LIMIT as integer
    $stmt = $pdo->prepare("
        DELETE FROM coupon_codes
        WHERE id IN (
            SELECT id FROM coupon_codes
            WHERE ctype=:c AND is_used=false
            ORDER BY id ASC
            LIMIT :q
        )
    ");
    $stmt->bindValue(":c", $ctype, PDO::PARAM_INT);
    $stmt->bindValue(":q", $qty, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}

function take_coupons(int $ctype, int $qty, int $user_id): ?array {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT id, code FROM coupon_codes
            WHERE ctype=:c AND is_used=false
            ORDER BY id ASC
            LIMIT :q
            FOR UPDATE
        ");
        $stmt->bindValue(":c", $ctype, PDO::PARAM_INT);
        $stmt->bindValue(":q", $qty, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) < $qty) {
            $pdo->rollBack();
            return null;
        }

        $ids = array_map(fn($r)=> (int)$r["id"], $rows);
        $in  = implode(",", $ids);

        $upd = $pdo->prepare("
            UPDATE coupon_codes
            SET is_used=true, used_by=:u, used_at=NOW()
            WHERE id IN ($in)
        ");
        $upd->execute([":u"=>$user_id]);

        $pdo->commit();
        return array_map(fn($r)=> (string)$r["code"], $rows);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_order(int $user_id, string $otype, string $status, array $fields=[]): int {
    global $pdo;

    $cols = ["user_id","otype","status"];
    $vals = [":uid",":ot",":st"];
    $params = [":uid"=>$user_id, ":ot"=>$otype, ":st"=>$status];

    $allowed = ["method","coins_requested","gift_amount","photo_file_id","ctype","qty","total_cost","codes_text"];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) {
            $cols[] = $k;
            $vals[] = ":" . $k;
            $params[":" . $k] = $fields[$k];
        }
    }

    $sql = "INSERT INTO orders(" . implode(",",$cols) . ") VALUES(" . implode(",",$vals) . ") RETURNING id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function update_order(int $order_id, array $fields=[]): void {
    global $pdo;
    if (!$fields) return;
    $sets = [];
    $params = [":id"=>$order_id];
    foreach ($fields as $k=>$v) {
        $sets[] = "{$k}=:$k";
        $params[":$k"] = $v;
    }
    $sql = "UPDATE orders SET " . implode(",",$sets) . " WHERE id=:id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function get_order(int $order_id): ?array {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=:id");
    }
    $stmt->execute([":id"=>$order_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function list_user_orders(int $user_id, int $limit=15): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id=:u ORDER BY id DESC LIMIT :l");
    $stmt->bindValue(":u", $user_id, PDO::PARAM_INT);
    $stmt->bindValue(":l", $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function list_pending_deposits(int $limit = 25): array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT o.*, u.username
        FROM orders o
        LEFT JOIN users u ON u.user_id = o.user_id
        WHERE o.otype='DEPOSIT' AND o.status='AWAITING_ADMIN'
        ORDER BY o.id DESC
        LIMIT :l
    ");
    $stmt->bindValue(":l", $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * 🚀 Combined DB query (reduces 3 queries -> 1)
 */
function get_buy_data(int $user_id, int $ctype): array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT
            COALESCE((SELECT COUNT(1) FROM coupon_codes WHERE ctype=:c AND is_used=false), 0) AS stock,
            COALESCE((SELECT price FROM coupon_prices WHERE ctype=:c), 0) AS price,
            COALESCE((SELECT diamonds FROM users WHERE user_id=:u), 0) AS balance
    ");
    $stmt->execute([":c"=>$ctype, ":u"=>$user_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        "stock"   => (int)($r["stock"] ?? 0),
        "price"   => (int)($r["price"] ?? 0),
        "balance" => (int)($r["balance"] ?? 0),
    ];
}

// ===============================
// ROUTING UPDATE
// ===============================
$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// ===============================
// MESSAGE HANDLER
// ===============================
if ($message) {
    $chat_id  = (int)$message["chat"]["id"];
    $user_id  = (int)$message["from"]["id"];
    $username = $message["from"]["username"] ?? ($message["from"]["first_name"] ?? "user");
    $text     = $message["text"] ?? null;
    $photo    = $message["photo"] ?? null;

    ensure_user($user_id, (string)$username);
    $is_admin = isAdmin($user_id);

    $st    = get_state($user_id);
    $state = $st["state"];
    $data  = $st["data"];

    if ($text === "/start") {
        clear_state($user_id);
        sendMessage($chat_id, "✅ Welcome!\nChoose an option:", main_menu($is_admin));
        return;
    }

    if ($text === "⬅️ Back") {
        clear_state($user_id);
        sendMessage($chat_id, "✅ Back to menu.", main_menu($is_admin));
        return;
    }

    if ($text === "🛠 Admin Panel") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        clear_state($user_id);
        sendMessage($chat_id, "🛠 Admin Panel:", admin_menu());
        return;
    }

    if ($text === "💰 Balance") {
        $bal = get_user_coins($user_id);
        sendMessage($chat_id, "💰 Your Balance: <b>{$bal}</b> Coins 🪙", main_menu($is_admin));
        return;
    }

    if ($text === "📦 My Orders") {
        $orders = list_user_orders($user_id, 15);
        if (!$orders) {
            sendMessage($chat_id, "📦 No orders found.", main_menu($is_admin));
            return;
        }
        $out = "📦 <b>Your Orders</b>\n━━━━━━━━━━━━━━━\n";
        foreach ($orders as $o) {
            $t = !empty($o["created_at"]) ? date("d M Y, h:i A", strtotime($o["created_at"])) : date("d M Y, h:i A");
            if (($o["otype"] ?? "") === "DEPOSIT") {
                $out .= "🧾 #{$o["id"]} | DEPOSIT | {$o["status"]}\n";
                $out .= "💳 {$o["method"]} | 🪙 {$o["coins_requested"]} | 🕒 {$t}\n";
            } else {
                $out .= "🧾 #{$o["id"]} | COUPON {$o["ctype"]} x{$o["qty"]} | {$o["status"]}\n";
                $out .= "🪙 Cost: {$o["total_cost"]} | 🕒 {$t}\n";
            }
            $out .= "━━━━━━━━━━━━━━━\n";
        }
        sendMessage($chat_id, $out, main_menu($is_admin));
        return;
    }

    // ---------------- Add Coins ----------------
    if ($text === "➕ Add Coins") {
        clear_state($user_id);
        $msg = "💳 <b>Select Payment Method:</b>\n\n".
               "⚠️ <b>Under Maintenance:</b>\n".
               "🛠️ PhonePe Gift Card\n\n".
               "Please use other methods for deposit.";

        $rm = [
            "inline_keyboard" => [
                [
                    ["text"=>"🎁 Amazon Gift Card", "callback_data"=>"pay:amazon"],
                    ["text"=>"🏦 UPI", "callback_data"=>"pay:upi"]
                ]
            ]
        ];
        sendMessage($chat_id, $msg, $rm);
        return;
    }

    // ---------------- Buy Coupon ----------------
    if ($text === "🛒 Buy Coupon") {
        clear_state($user_id);

        $types = [500,1000,2000,4000];
        $out = "🛒 <b>Select a coupon type:</b>\n\n";
        foreach ($types as $c) {
            $price = get_price($c);
            $stock = stock_count($c);
            $label = ($c==1000 ? "1K" : ($c==2000 ? "2K" : ($c==4000 ? "4K" : "500")));
            $out .= "• <b>{$label}</b> (🪙 <b>{$price}</b> coins) | Stock: <b>{$stock}</b>\n";
        }

        $rm = ["inline_keyboard"=>[
            [["text"=>"Buy 500", "callback_data"=>"buy:500"], ["text"=>"Buy 1K", "callback_data"=>"buy:1000"]],
            [["text"=>"Buy 2K", "callback_data"=>"buy:2000"], ["text"=>"Buy 4K", "callback_data"=>"buy:4000"]],
        ]];

        sendMessage($chat_id, $out, $rm);
        return;
    }

    // Ask qty -> confirm
    if ($state === "AWAIT_BUY_QTY" && $text !== null) {
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Please send a valid quantity number."); return; }

        $qty = (int)$text;
        if ($qty <= 0) { sendMessage($chat_id, "❌ Quantity must be 1 or more. Send again:"); return; }

        $ctype = (int)($data["ctype"] ?? 0);
        if (!in_array($ctype, [500,1000,2000,4000], true)) {
            clear_state($user_id);
            sendMessage($chat_id, "❌ Invalid type. Please try again.", main_menu($is_admin));
            return;
        }

        // 🚀 single DB call for stock/price/balance
        $bd = get_buy_data($user_id, $ctype);
        $available = $bd["stock"];
        $price     = $bd["price"];
        $bal       = $bd["balance"];

        if ($available < $qty) {
            clear_state($user_id);
            sendMessage($chat_id, "❌ Not enough stock! Available: <b>{$available}</b>", main_menu($is_admin));
            return;
        }

        $need = $price * $qty;
        if ($bal < $need) {
            clear_state($user_id);
            sendMessage($chat_id, "❌ Not enough coins!\nNeeded: <b>{$need}</b> | You have: <b>{$bal}</b>", main_menu($is_admin));
            return;
        }

        $time = date("d M Y, h:i A");
        $summary = "📝 <b>Order Summary</b>\n━━━━━━━━━━━━━━━\n".
                   "🎟️ Type: <b>{$ctype}</b>\n".
                   "📦 Qty: <b>{$qty}</b>\n".
                   "🪙 Total Cost: <b>{$need}</b> coins\n".
                   "💰 Your Balance: <b>{$bal}</b>\n".
                   "📅 Time: <b>{$time}</b>\n".
                   "━━━━━━━━━━━━━━━\n\nConfirm purchase?";

        set_state($user_id, "AWAIT_BUY_CONFIRM", ["ctype"=>$ctype, "qty"=>$qty, "need"=>$need]);

        $rm = ["inline_keyboard"=>[
            [["text"=>"✅ Confirm", "callback_data"=>"buy_ok"], ["text"=>"❌ Cancel", "callback_data"=>"buy_cancel"]]
        ]];

        sendMessage($chat_id, $summary, $rm);
        return;
    }

    // ===== Amazon flow =====
    if ($state === "AWAIT_AMAZON_COINS" && $text !== null) {
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid number (minimum 30)."); return; }
        $coins = (int)$text;
        if ($coins < 30) { sendMessage($chat_id, "❌ Minimum is 30 coins. Send again:"); return; }

        $order_id = create_order($user_id, "DEPOSIT", "PENDING", [
            "method" => "AMAZON",
            "coins_requested" => $coins
        ]);

        $time = date("d M Y, h:i A");
        $summary = "📝 <b>Order Summary:</b>\n━━━━━━━━━━━━━━━\n".
                   "💹 Rate: 1 Rs = 1 Coin 🪙\n".
                   "💵 Amount: <b>{$coins}</b>\n".
                   "🪙 Coins to Receive: <b>{$coins}</b> 🪙\n".
                   "💳 Method: <b>Amazon Gift Card</b>\n".
                   "📅 Time: <b>{$time}</b>\n".
                   "━━━━━━━━━━━━━━━\n\nClick below to proceed.";

        $rm = ["inline_keyboard" => [
            [["text"=>"✅ Submit Gift Card", "callback_data"=>"deposit_submit:$order_id"]]
        ]];

        clear_state($user_id);
        sendMessage($chat_id, $summary, $rm);
        return;
    }

    if ($state === "AWAIT_GIFT_CODE" && $text !== null) {
        $gift_code = trim($text);
        if ($gift_code === "") { sendMessage($chat_id, "❌ Enter your Amazon Gift Card :"); return; }

        $order_id = (int)($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); return; }

        update_order($order_id, ["method"=>"AMAZON | CODE: ".$gift_code, "status"=>"PENDING"]);
        set_state($user_id, "AWAIT_AMAZON_PHOTO", ["order_id"=>$order_id]);
        sendMessage($chat_id, "📸 Now upload a screenshot of the gift card:");
        return;
    }

    if ($state === "AWAIT_AMAZON_PHOTO" && $photo) {
        $order_id = (int)($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); return; }

        $file_id = end($photo)["file_id"];
        update_order($order_id, ["photo_file_id"=>$file_id, "status"=>"AWAITING_ADMIN"]);
        clear_state($user_id);

        sendMessage($chat_id, "✅ Admin is checking your payment.\n⏳ Please wait for approval.");

        $o = get_order($order_id);
        $time = !empty($o["created_at"]) ? date("d M Y, h:i A", strtotime($o["created_at"])) : date("d M Y, h:i A");

        $codeText = "";
        if (!empty($o["method"]) && strpos($o["method"], "CODE:") !== false) {
            $codeText = trim(substr($o["method"], strpos($o["method"], "CODE:") + 5));
        }

        $adminText = "🆕 <b>Deposit Request (Amazon)</b>\n".
                     "🧾 Order: <b>#{$order_id}</b>\n".
                     "👤 User: @{$username} (<code>{$user_id}</code>)\n".
                     "🪙 Coins: <b>{$o["coins_requested"]}</b>\n".
                     "🎁 Gift Card: <b>".esc($codeText)."</b>\n".
                     "⏰ Time: <b>{$time}</b>\n";

        $adminRm = ["inline_keyboard" => [[
            ["text"=>"✅ Accept", "callback_data"=>"admin_dep_ok:$order_id"],
            ["text"=>"❌ Decline", "callback_data"=>"admin_dep_no:$order_id"]
        ]]];

        foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
            sendPhotoMsg((int)$aid, $file_id, $adminText, $adminRm);
        }
        return;
    }

    // ===== UPI flow =====
    if ($state === "AWAIT_UPI_COINS" && $text !== null) {
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid number (minimum 30)."); return; }
        $coins = (int)$text;
        if ($coins < 30) { sendMessage($chat_id, "❌ Minimum is 30 coins. Send again:"); return; }

        $qr_file_id = get_setting("upi_qr_file_id");
        if (!$qr_file_id) {
            clear_state($user_id);
            sendMessage($chat_id, "❌ UPI QR is not set yet. Please contact admin.");
            return;
        }

        $order_id = create_order($user_id, "DEPOSIT", "PENDING", [
            "method" => "UPI",
            "coins_requested" => $coins
        ]);

        clear_state($user_id);

        $caption = "💳<b>Payement Request</b>\n\n".
                   "🎫Order- <b>#{$order_id}</b>\n".
                   "💰Amount- <b>{$coins}</b>\n\n".
                   "✅ After payment, click \"I Have Paid\" below";

        $rm = ["inline_keyboard" => [
            [["text"=>"✅ I Have Paid", "callback_data"=>"upi_paid:$order_id"]]
        ]];

        sendPhotoMsg($chat_id, $qr_file_id, $caption, $rm);
        return;
    }

    if ($state === "AWAIT_UPI_PAYER_NAME" && $text !== null) {
        $payer = trim($text);
        if ($payer === "") { sendMessage($chat_id, "❌ Please send payer name:"); return; }

        $order_id = (int)($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); return; }

        $o = get_order($order_id);
        if (!$o) { clear_state($user_id); sendMessage($chat_id, "❌ Order not found."); return; }

        update_order($order_id, ["method"=>"UPI | NAME: ".$payer, "status"=>"PENDING"]);
        set_state($user_id, "AWAIT_UPI_SS", ["order_id"=>$order_id]);
        sendMessage($chat_id, "📸 Now upload a screenshot of payment:");
        return;
    }

    if ($state === "AWAIT_UPI_SS" && $photo) {
        $order_id = (int)($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); return; }

        $file_id = end($photo)["file_id"];
        update_order($order_id, ["photo_file_id"=>$file_id, "status"=>"AWAITING_ADMIN"]);
        clear_state($user_id);

        sendMessage($chat_id, "✅ Admin is reviewing your payment.\n⏳ Please wait for approval.");

        $o = get_order($order_id);
        $time = !empty($o["created_at"]) ? date("d M Y, h:i A", strtotime($o["created_at"])) : date("d M Y, h:i A");

        $payerName = "";
        if (!empty($o["method"]) && strpos($o["method"], "NAME:") !== false) {
            $payerName = trim(substr($o["method"], strpos($o["method"], "NAME:") + 5));
        }

        $adminText = "🆕 <b>Deposit Request (UPI)</b>\n".
                     "🧾 Order: <b>#{$order_id}</b>\n".
                     "👤 User: @{$username} (<code>{$user_id}</code>)\n".
                     "🪙 Coins: <b>{$o["coins_requested"]}</b>\n".
                     "👤 Payer: <b>".esc($payerName)."</b>\n".
                     "⏰ Time: <b>{$time}</b>\n";

        $adminRm = ["inline_keyboard" => [[
            ["text"=>"✅ Accept", "callback_data"=>"admin_dep_ok:$order_id"],
            ["text"=>"❌ Decline", "callback_data"=>"admin_dep_no:$order_id"]
        ]]];

        foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
            sendPhotoMsg((int)$aid, $file_id, $adminText, $adminRm);
        }
        return;
    }

    // ================= ADMIN =================
    if ($text === "🧾 Update UPI QR") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        set_state($user_id, "ADMIN_AWAIT_UPI_QR", []);
        sendMessage($chat_id, "📸 Send the new UPI QR image now:");
        return;
    }

    if ($state === "ADMIN_AWAIT_UPI_QR" && $photo) {
        if (!$is_admin) { clear_state($user_id); return; }
        $file_id = end($photo)["file_id"];
        set_setting("upi_qr_file_id", $file_id);
        clear_state($user_id);
        sendMessage($chat_id, "✅ UPI QR updated successfully.", admin_menu());
        return;
    }

    if ($text === "📦 Stock") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        $types = [500,1000,2000,4000];
        $out = "📦 <b>Stock</b>\n━━━━━━━━━━━━━━━\n";
        foreach($types as $c){ $out .= "• {$c}: <b>".stock_count($c)."</b>\n"; }
        sendMessage($chat_id, $out, admin_menu());
        return;
    }

    if ($text === "📋 Orders List") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        $pending = list_pending_deposits(25);
        if (!$pending) { sendMessage($chat_id, "✅ No pending deposits right now.", admin_menu()); return; }

        sendMessage($chat_id, "📋 <b>Pending Deposits:</b>\nShowing latest pending requests:", admin_menu());

        foreach ($pending as $o) {
            $oid = (int)$o["id"];
            $uid = (int)$o["user_id"];
            $un  = $o["username"] ?: "user";
            $coins = (int)$o["coins_requested"];
            $time = !empty($o["created_at"]) ? date("d M Y, h:i A", strtotime($o["created_at"])) : date("d M Y, h:i A");

            $extra = "";
            if (!empty($o["method"])) {
                if (strpos($o["method"], "AMAZON") === 0 && strpos($o["method"], "CODE:") !== false) {
                    $codeText = trim(substr($o["method"], strpos($o["method"], "CODE:") + 5));
                    $extra = "🎁 Gift Card: <b>".esc($codeText)."</b>\n";
                } elseif (strpos($o["method"], "UPI") === 0 && strpos($o["method"], "NAME:") !== false) {
                    $payer = trim(substr($o["method"], strpos($o["method"], "NAME:") + 5));
                    $extra = "👤 Payer: <b>".esc($payer)."</b>\n";
                }
            }

            $txt = "🆕 <b>Deposit Request</b>\n"
                 . "🧾 Order: <b>#{$oid}</b>\n"
                 . "👤 User: @{$un} (<code>{$uid}</code>)\n"
                 . "🪙 Coins: <b>{$coins}</b>\n"
                 . $extra
                 . "⏰ Time: <b>{$time}</b>\n";

            $rm = ["inline_keyboard" => [[
                ["text"=>"✅ Accept", "callback_data"=>"admin_dep_ok:$oid"],
                ["text"=>"❌ Decline", "callback_data"=>"admin_dep_no:$oid"]
            ]]];

            if (!empty($o["photo_file_id"])) sendPhotoMsg($chat_id, $o["photo_file_id"], $txt, $rm);
            else sendMessage($chat_id, $txt, $rm);
        }
        return;
    }

    if ($text === "💰 Change Prices") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_price:500"], ["text"=>"1K", "callback_data"=>"admin_price:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_price:2000"], ["text"=>"4K", "callback_data"=>"admin_price:4000"]],
        ]];
        sendMessage($chat_id, "Select type to change price:", $rm);
        return;
    }

    if ($text === "🎁 Get Free Code") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_free:500"], ["text"=>"1K", "callback_data"=>"admin_free:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_free:2000"], ["text"=>"4K", "callback_data"=>"admin_free:4000"]],
        ]];
        sendMessage($chat_id, "Select coupon type to get FREE code:", $rm);
        return;
    }

    if ($text === "➕ Add Coupon") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_add:500"], ["text"=>"1K", "callback_data"=>"admin_add:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_add:2000"], ["text"=>"4K", "callback_data"=>"admin_add:4000"]],
        ]];
        sendMessage($chat_id, "Select type to add coupons:", $rm);
        return;
    }

    if ($text === "➖ Remove Coupon") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); return; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_rem:500"], ["text"=>"1K", "callback_data"=>"admin_rem:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_rem:2000"], ["text"=>"4K", "callback_data"=>"admin_rem:4000"]],
        ]];
        sendMessage($chat_id, "Select type to remove coupons:", $rm);
        return;
    }

    if ($state === "ADMIN_AWAIT_PRICE" && $text !== null) {
        if (!$is_admin) { clear_state($user_id); return; }
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid price number."); return; }
        $price = (int)$text;
        $ctype = (int)($data["ctype"] ?? 0);
        set_price($ctype, $price);
        clear_state($user_id);
        sendMessage($chat_id, "✅ Price updated for {$ctype} => {$price} coins.", admin_menu());
        return;
    }

    if ($state === "ADMIN_AWAIT_ADD_CODES" && $text !== null) {
        if (!$is_admin) { clear_state($user_id); return; }
        $ctype = (int)($data["ctype"] ?? 0);
        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        $added = add_coupons($ctype, $lines);
        clear_state($user_id);
        sendMessage($chat_id, "✅ Added <b>{$added}</b> coupons to {$ctype}.", admin_menu());
        return;
    }

    if ($state === "ADMIN_AWAIT_REMOVE_QTY" && $text !== null) {
        if (!$is_admin) { clear_state($user_id); return; }
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid number."); return; }
        $qty = (int)$text;
        $ctype = (int)($data["ctype"] ?? 0);
        $removed = remove_coupons($ctype, $qty);
        clear_state($user_id);
        sendMessage($chat_id, "✅ Removed <b>{$removed}</b> from {$ctype}.", admin_menu());
        return;
    }

    // Unexpected photo
    if ($photo && !in_array($state, ["AWAIT_AMAZON_PHOTO","AWAIT_UPI_SS","ADMIN_AWAIT_UPI_QR"], true)) {
        sendMessage($chat_id, "❌ Photo not expected now. Use menu buttons.", main_menu($is_admin));
        return;
    }

    sendMessage($chat_id, "❓ Use the menu buttons.", main_menu($is_admin));
    return;
}

// ===============================
// CALLBACK HANDLER
// ===============================
if ($callback) {
    $cb_id   = $callback["id"];
    $user_id = (int)$callback["from"]["id"];
    $username= $callback["from"]["username"] ?? ($callback["from"]["first_name"] ?? "user");
    $chat_id = (int)$callback["message"]["chat"]["id"];
    $msg_id  = (int)$callback["message"]["message_id"];
    $data    = (string)($callback["data"] ?? "");

    ensure_user($user_id, (string)$username);
    $is_admin = isAdmin($user_id);

    // Payment method selection
    if ($data === "pay:amazon") {
        answerCallback($cb_id, "Amazon selected");
        set_state($user_id, "AWAIT_AMAZON_COINS", []);
        sendMessage($chat_id, "Enter the number of coins to add (Method: Amazon):\n\n✅ Minimum: 30");
        return;
    }

    if ($data === "pay:upi") {
        answerCallback($cb_id, "UPI selected");
        set_state($user_id, "AWAIT_UPI_COINS", []);
        sendMessage($chat_id, "How much coins you need? (Minimum: 30)");
        return;
    }

    // Amazon submit -> ask gift card code
    if (preg_match('/^deposit_submit:(\d+)$/', $data, $m)) {
        $order_id = (int)$m[1];
        answerCallback($cb_id, "Proceeding...");
        set_state($user_id, "AWAIT_GIFT_CODE", ["order_id"=>$order_id]);
        sendMessage($chat_id, "Enter your Amazon Gift Card :");
        return;
    }

    // UPI "I Have Paid"
    if (preg_match('/^upi_paid:(\d+)$/', $data, $m)) {
        $order_id = (int)$m[1];
        $o = get_order($order_id);
        if (!$o || (int)$o["user_id"] !== $user_id) {
            answerCallback($cb_id, "Invalid order", true);
            return;
        }
        if (($o["status"] ?? "") !== "PENDING") {
            answerCallback($cb_id, "Already submitted", true);
            return;
        }
        answerCallback($cb_id, "OK");
        set_state($user_id, "AWAIT_UPI_PAYER_NAME", ["order_id"=>$order_id]);
        sendMessage($chat_id, "Send the payer name (person who paid):");
        return;
    }

    // BUY COUPON: pick type
    if (preg_match('/^buy:(500|1000|2000|4000)$/', $data, $m)) {
        $ctype = (int)$m[1];
        answerCallback($cb_id, "Selected $ctype");
        set_state($user_id, "AWAIT_BUY_QTY", ["ctype"=>$ctype]);
        sendMessage($chat_id, "How many {$ctype} coupons do you want to buy?\nPlease send the quantity:");
        return;
    }

    if ($data === "buy_cancel") {
        answerCallback($cb_id, "Cancelled");
        clear_state($user_id);
        editMessage($chat_id, $msg_id, "❌ Purchase cancelled.");
        return;
    }

    if ($data === "buy_ok") {
        answerCallback($cb_id, "Processing...");
        $st = get_state($user_id);
        if (($st["state"] ?? null) !== "AWAIT_BUY_CONFIRM") {
            answerCallback($cb_id, "No pending purchase.", true);
            return;
        }

        $ctype = (int)($st["data"]["ctype"] ?? 0);
        $qty   = (int)($st["data"]["qty"] ?? 0);
        $need  = (int)($st["data"]["need"] ?? 0);

        if (!in_array($ctype, [500,1000,2000,4000], true) || $qty<=0 || $need<=0) {
            clear_state($user_id);
            answerCallback($cb_id, "Invalid purchase.", true);
            return;
        }

        // 🚀 single DB call for stock + balance
        $bd = get_buy_data($user_id, $ctype);
        if ($bd["stock"] < $qty) {
            clear_state($user_id);
            editMessage($chat_id, $msg_id, "❌ Not enough stock! Available: <b>{$bd["stock"]}</b>");
            return;
        }
        if ($bd["balance"] < $need) {
            clear_state($user_id);
            editMessage($chat_id, $msg_id, "❌ Not enough coins!\nNeeded: <b>{$need}</b> | You have: <b>{$bd["balance"]}</b>");
            return;
        }

        $codes = take_coupons($ctype, $qty, $user_id);
        if (!$codes) {
            clear_state($user_id);
            editMessage($chat_id, $msg_id, "❌ Stock error. Try again.");
            return;
        }

        // Deduct coins
        add_user_coins($user_id, -$need);

        $codesText = implode("\n", $codes);
        $order_id = create_order($user_id, "COUPON", "COMPLETED", [
            "ctype"=>$ctype,
            "qty"=>$qty,
            "total_cost"=>$need,
            "codes_text"=>$codesText
        ]);

        clear_state($user_id);

        editMessage(
            $chat_id,
            $msg_id,
            "✅ <b>Purchase Successful</b>\n".
            "🧾 Order: <b>#{$order_id}</b>\n".
            "🎟️ Type: <b>{$ctype}</b>\n".
            "📦 Qty: <b>{$qty}</b>\n".
            "🪙 Cost: <b>{$need}</b> coins\n\n".
            "🔑 <b>Your Codes:</b>\n<code>{$codesText}</code>"
        );
        return;
    }

    // Admin accept deposit
    if (preg_match('/^admin_dep_ok:(\d+)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); return; }
        $order_id = (int)$m[1];
        $o = get_order($order_id);
        if (!$o) { answerCallback($cb_id, "Order not found", true); return; }
        if (($o["status"] ?? "") !== "AWAITING_ADMIN") { answerCallback($cb_id, "Already processed", true); return; }

        update_order($order_id, ["status"=>"APPROVED"]);
        add_user_coins((int)$o["user_id"], (int)$o["coins_requested"]);

        answerCallback($cb_id, "Accepted ✅");
        editMessage($chat_id, $msg_id, "✅ Accepted deposit order #{$order_id}");
        sendMessage((int)$o["user_id"], "✅ Your deposit has been <b>approved</b>!\n🪙 Added: <b>{$o["coins_requested"]}</b> Coins 🪙");
        return;
    }

    // Admin decline deposit
    if (preg_match('/^admin_dep_no:(\d+)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); return; }
        $order_id = (int)$m[1];
        $o = get_order($order_id);
        if (!$o) { answerCallback($cb_id, "Order not found", true); return; }
        if (($o["status"] ?? "") !== "AWAITING_ADMIN") { answerCallback($cb_id, "Already processed", true); return; }

        update_order($order_id, ["status"=>"DECLINED"]);
        answerCallback($cb_id, "Declined ❌");
        editMessage($chat_id, $msg_id, "❌ Declined deposit order #{$order_id}");
        sendMessage((int)$o["user_id"], "❌ Your deposit has been <b>declined</b>.");
        return;
    }

    // Admin choose type for price
    if (preg_match('/^admin_price:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); return; }
        $ctype = (int)$m[1];
        answerCallback($cb_id, "Type $ctype");
        set_state($user_id, "ADMIN_AWAIT_PRICE", ["ctype"=>$ctype]);
        sendMessage($chat_id, "Send new price (coins) for {$ctype}:");
        return;
    }

    // Admin get free code
    if (preg_match('/^admin_free:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); return; }
        $ctype = (int)$m[1];
        $codes = take_coupons($ctype, 1, $user_id);
        if (!$codes) { answerCallback($cb_id, "No stock!", true); sendMessage($chat_id, "❌ No stock for {$ctype}."); return; }
        answerCallback($cb_id, "Here is your code ✅");
        sendMessage($chat_id, "🎁 FREE CODE ({$ctype}):\n<code>{$codes[0]}</code>");
        return;
    }

    // Admin add coupon type
    if (preg_match('/^admin_add:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); return; }
        $ctype = (int)$m[1];
        answerCallback($cb_id, "Send codes");
        set_state($user_id, "ADMIN_AWAIT_ADD_CODES", ["ctype"=>$ctype]);
        sendMessage($chat_id, "Send coupons for {$ctype} (one per line):");
        return;
    }

    // Admin remove coupon type
    if (preg_match('/^admin_rem:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); return; }
        $ctype = (int)$m[1];
        answerCallback($cb_id, "Remove qty");
        set_state($user_id, "ADMIN_AWAIT_REMOVE_QTY", ["ctype"=>$ctype]);
        $avail = stock_count($ctype);
        sendMessage($chat_id, "Available stock for {$ctype}: <b>{$avail}</b>\nHow many do you want to remove?");
        return;
    }

    answerCallback($cb_id, "Unknown action");
    return;
}
