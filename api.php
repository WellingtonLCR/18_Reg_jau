<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
session_start();

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
$path = $_GET['resource'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $pdo = db();
    if ($method === 'POST' && $path === 'login') {
        foreach (['login', 'password'] as $field) if (empty($body[$field])) json_response(['error' => 'Informe usuário e senha.'], 422);
        $stmt = $pdo->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE email = ? OR name = ? LIMIT 1');
        $stmt->execute([$body['login'], $body['login']]);
        $user = $stmt->fetch();
        if (!$user || !$user['active'] || !password_verify($body['password'], $user['password_hash'])) json_response(['error' => 'Usuário ou senha inválidos.'], 401);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id']; $_SESSION['role'] = $user['role'];
        unset($user['password_hash']);
        json_response(['user' => $user]);
    }
    if ($method === 'GET' && $path === 'products') {
        $stmt = $pdo->query("SELECT p.id,p.name,p.internal_code,p.description,p.sale_price,p.main_image,c.name category,
            COALESCE(SUM(CASE WHEN v.active=1 THEN v.stock_quantity ELSE 0 END),0) stock
            FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN product_variants v ON v.product_id=p.id
            WHERE p.active=1 GROUP BY p.id ORDER BY p.created_at DESC");
        json_response(['products' => $stmt->fetchAll()]);
    }
    if ($method === 'GET' && preg_match('/^products\/(\d+)$/', $path, $match)) {
        $stmt = $pdo->prepare('SELECT p.*, c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1');
        $stmt->execute([$match[1]]); $product = $stmt->fetch();
        if (!$product) json_response(['error' => 'Produto não encontrado'], 404);
        $variants = $pdo->prepare('SELECT id,size,sku,stock_quantity FROM product_variants WHERE product_id=? AND active=1');
        $variants->execute([$product['id']]); $product['variants'] = $variants->fetchAll();
        json_response(['product' => $product]);
    }
    if ($method === 'POST' && $path === 'register') {
        foreach (['full_name','phone','email','password'] as $field) if (empty($body[$field])) json_response(['error' => "Campo {$field} é obrigatório"],422);
        $stmt = $pdo->prepare('INSERT INTO customers (full_name,phone,email,password_hash,accepts_notifications) VALUES (?,?,?,?,?)');
        $stmt->execute([$body['full_name'],$body['phone'],$body['email'],password_hash($body['password'], PASSWORD_DEFAULT),!empty($body['accepts_notifications'])]);
        json_response(['customer_id'=>(int)$pdo->lastInsertId()], 201);
    }
    if ($method === 'POST' && $path === 'orders') {
        foreach (['customer_id','items','payment_method'] as $field) if (empty($body[$field])) json_response(['error' => "Campo {$field} é obrigatório"],422);
        $pdo->beginTransaction(); $subtotal = 0; $items = [];
        foreach ($body['items'] as $requestItem) {
            $stmt = $pdo->prepare('SELECT v.id,v.size,v.stock_quantity,p.name,p.sale_price FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=? AND v.active=1 AND p.active=1 FOR UPDATE');
            $stmt->execute([(int)$requestItem['variant_id']]); $row=$stmt->fetch(); $qty=(int)$requestItem['quantity'];
            if (!$row || $qty < 1 || $row['stock_quantity'] < $qty) throw new RuntimeException('Estoque insuficiente para um dos itens.');
            $subtotal += $row['sale_price']*$qty; $items[] = [$row,$qty];
        }
        $shipping = (float)($body['shipping_cost'] ?? 0); $discount = $body['payment_method']==='pix' ? round($subtotal*.05,2) : 0; $total=$subtotal-$discount+$shipping;
        $number='RJ-'.date('Ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
        $stmt=$pdo->prepare('INSERT INTO orders (order_number,customer_id,address_id,subtotal,discount,shipping_cost,total) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$number,$body['customer_id'],$body['address_id']??null,$subtotal,$discount,$shipping,$total]); $orderId=(int)$pdo->lastInsertId();
        foreach($items as [$row,$qty]) { $new=$row['stock_quantity']-$qty; $pdo->prepare('INSERT INTO order_items (order_id,variant_id,product_name,size,unit_price,quantity,line_total) VALUES (?,?,?,?,?,?,?)')->execute([$orderId,$row['id'],$row['name'],$row['size'],$row['sale_price'],$qty,$row['sale_price']*$qty]); $pdo->prepare('UPDATE product_variants SET stock_quantity=? WHERE id=?')->execute([$new,$row['id']]); $pdo->prepare("INSERT INTO stock_movements (variant_id,type,quantity,previous_quantity,new_quantity,reference_type,reference_id) VALUES (?,'venda',?,?,?,?,?)")->execute([$row['id'],-$qty,$row['stock_quantity'],$new,'pedido',$orderId]); }
        $pdo->prepare('INSERT INTO payments (order_id,method,amount) VALUES (?,?,?)')->execute([$orderId,$body['payment_method'],$total]);
        $pdo->commit(); json_response(['order_id'=>$orderId,'order_number'=>$number,'total'=>$total],201);
    }
    json_response(['error'=>'Rota não encontrada'],404);
} catch (RuntimeException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['error'=>$e->getMessage()],422); }
catch (Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); json_response(['error'=>'Erro interno. Verifique a conexão e o banco de dados.'],500); }
